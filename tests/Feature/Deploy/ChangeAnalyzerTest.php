<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Deploy;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Database\DbCheck;
use Cpdeploy\Deploy\ChangeAnalyzer;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Project\ProjectDetector;
use Cpdeploy\Runtime\PackageManagerChoice;
use Cpdeploy\Tests\Support\GitFixture;
use Cpdeploy\Tests\Support\TestCase;

final class ChangeAnalyzerTest extends TestCase
{
    private GitFixture $repo;
    private GitRepository $git;
    private string $mirror;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GitFixture($this->tmp . '/work');
        $this->mirror = $this->tmp . '/work.git';
        $this->git = $this->services()->git();
    }

    /**
     * @param array<string, string> $packages
     */
    private static function lock(array $packages): string
    {
        $list = [];
        foreach ($packages as $name => $version) {
            $list[] = ['name' => $name, 'version' => $version];
        }

        return (string) json_encode(['packages' => $list, 'packages-dev' => []]);
    }

    private function base(): string
    {
        $this->repo->write('composer.json', '{"require":{"php":"^8.2","laravel/framework":"^12.0"}}');
        $this->repo->write('composer.lock', self::lock(['laravel/framework' => 'v12.31.0', 'monolog/monolog' => '3.9.0', 'old/pkg' => '1.0.0']));
        $this->repo->write('artisan', "#!/usr/bin/env php\n<?php\n");
        $this->repo->write('package.json', '{"scripts":{"build":"vite build"},"devDependencies":{"vite":"^6"}}');
        $this->repo->write('package-lock.json', '{}');
        $this->repo->write('database/migrations/2026_01_01_000000_create_users_table.php', '<?php');
        $this->repo->write('public/index.php', '<?php');
        $this->repo->write('public/.htaccess', "RewriteEngine On\n");

        return $this->repo->commit('Base');
    }

    /**
     * @covers-req CHG-01
     * @covers-req CHG-02
     */
    public function testFirstDeployCountsEverythingAsNew(): void
    {
        $sha = $this->base();
        $this->repo->push();

        $changes = (new ChangeAnalyzer($this->git))->analyze($this->mirror, null, $sha, 'public');

        self::assertTrue($changes->firstDeploy());
        self::assertSame(1, $changes->commitCount);
        self::assertSame(['2026_01_01_000000_create_users_table'], $changes->migrationsAdded);
        self::assertTrue($changes->composerChanged);
        self::assertTrue($changes->nodeChanged);
        self::assertCount(3, $changes->lockDiff['added']);
        self::assertTrue($changes->frontendRelevant());
    }

    /**
     * @covers-req CHG-02
     * @covers-req GIT-12
     */
    public function testDiffBetweenLiveAndTarget(): void
    {
        $live = $this->base();
        $this->repo->write('composer.json', '{"require":{"php":"^8.3","laravel/framework":"^12.0"}}');
        $this->repo->write('composer.lock', self::lock(['laravel/framework' => 'v12.32.0', 'monolog/monolog' => '3.9.0', 'new/pkg' => '2.0.0']));
        $this->repo->write('database/migrations/2026_02_01_000000_add_coupons.php', '<?php');
        $this->repo->write('database/migrations/2026_01_01_000000_create_users_table.php', '<?php // edited');
        $this->repo->write('public/.htaccess', "RewriteEngine On\nOptions -Indexes\n");
        $this->repo->commit('Coupons');
        $this->repo->write('.nvmrc', "22\n");
        $target = $this->repo->commit('Node 22');
        $this->repo->push();

        $changes = (new ChangeAnalyzer($this->git))->analyze($this->mirror, $live, $target, 'public');

        self::assertFalse($changes->isRewind);
        self::assertSame(2, $changes->commitCount);
        self::assertSame(['Node 22', 'Coupons'], array_map(static fn ($c) => $c->subject, $changes->commits));
        self::assertTrue($changes->composerChanged);
        self::assertTrue($changes->phpRequirementChanged);
        self::assertSame(['new/pkg' => '2.0.0'], $changes->lockDiff['added']);
        self::assertSame(['old/pkg' => '1.0.0'], $changes->lockDiff['removed']);
        self::assertSame(['laravel/framework' => ['12.31.0', '12.32.0']], $changes->lockDiff['updated']);
        self::assertSame('1 updated, 1 added, 1 removed', ComposerInspector::summary($changes->lockDiff));
        self::assertSame(['2026_02_01_000000_add_coupons'], $changes->migrationsAdded);
        self::assertSame(['2026_01_01_000000_create_users_table'], $changes->migrationsModified);
        self::assertTrue($changes->nodeChanged);
        self::assertTrue($changes->htaccessChanged);

        $same = (new ChangeAnalyzer($this->git))->analyze($this->mirror, $target, $target, 'public');
        self::assertTrue($same->sameCommit);
    }

    /**
     * @covers-req CHG-02
     */
    public function testRewindAndDeletedMigrations(): void
    {
        $base = $this->base();
        $this->repo->write('database/migrations/2026_03_01_000000_temp.php', '<?php');
        $this->repo->write('README.md', 'x');
        $live = $this->repo->commit('Live 1');
        $this->repo->write('tests/ExampleTest.php', '<?php');
        $live = $this->repo->commit('Live 2');
        $this->repo->git('reset', '-q', '--hard', $base);
        $this->repo->write('tests/OnlyTests.php', '<?php');
        $target = $this->repo->commit('Rewritten');
        $this->repo->push();
        $this->repo->git('push', '-q', $this->mirror, $live . ':refs/heads/old');

        $changes = (new ChangeAnalyzer($this->git))->analyze($this->mirror, $live, $target, 'public');

        self::assertTrue($changes->isRewind);
        self::assertSame(1, $changes->commitCount);
        self::assertSame(2, $changes->behindCount);
        self::assertSame(['2026_03_01_000000_temp'], $changes->migrationsDeleted);
        // README.md changed back: outside vendor/storage/database/tests → the frontend is relevant.
        self::assertTrue($changes->frontendRelevant());
    }

    /**
     * @covers-req PRJ-01
     * @covers-req PRJ-02
     * @covers-req LAR-02
     * @covers-req NODE-07
     * @covers-req NODE-11
     */
    public function testProjectDetectionAndNodeFacts(): void
    {
        $sha = $this->base();
        $this->repo->push();
        $files = new CommitFiles($this->git, $this->mirror, $sha);
        $detector = new ProjectDetector();

        $info = $detector->detect($files);
        self::assertSame('laravel', $info->detectedType);
        self::assertSame('12.31.0', $info->laravelVersion);
        self::assertSame(12, $info->laravelMajor());
        self::assertTrue($info->hasScript('build'));
        self::assertFalse($info->usesMix);
        self::assertSame('public', $detector->defaultWebDir('laravel', $files));

        $config = new SiteConfig(SiteSchema::withDefaults(['name' => 'shop'], (new Presets())->for('laravel')));
        self::assertTrue(NodeInspector::builds($config, $info));
        self::assertSame([['public/build/manifest.json', 'public/build/.vite/manifest.json']], NodeInspector::expectFiles($config, $info));
        self::assertSame(['public/build'], NodeInspector::buildOutputs($config, $info));
        $inspector = new NodeInspector();
        $pm = $inspector->packageManager($config, $files);
        self::assertSame(PackageManagerChoice::NPM, $pm->name);
        self::assertTrue($inspector->hasLockfile($pm, $files));
        self::assertNull($inspector->spec($config, $files));

        // A plain PHP site and a static site.
        $this->repo->delete('artisan');
        $static = $this->repo->commit('No artisan');
        $this->repo->push();
        self::assertSame('static', $detector->detect(new CommitFiles($this->git, $this->mirror, $static))->detectedType);

        $this->repo->delete('package.json');
        $php = $this->repo->commit('No package.json');
        $this->repo->push();
        self::assertSame('php', $detector->detect(new CommitFiles($this->git, $this->mirror, $php))->detectedType);
    }

    /**
     * @covers-req DB-04
     * @covers-req SEC-03
     */
    public function testDbCheckWithSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded');
        }
        $check = new DbCheck($this->services()->shell());
        $db = $this->tmp . '/db.sqlite';
        touch($db);

        self::assertTrue($check->check(PHP_BINARY, ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db], $this->tmp)->ok());

        $missing = $check->check(PHP_BINARY, ['DB_CONNECTION' => 'sqlite'], $this->tmp);
        self::assertSame(DbCheck::FAILED, $missing->status);
        self::assertStringContainsString($this->tmp . '/database/database.sqlite', $missing->detail);

        $mysql = $check->check(PHP_BINARY, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_PASSWORD' => 'hunter2secret'], $this->tmp);
        self::assertContains($mysql->status, [DbCheck::FAILED, DbCheck::NO_DRIVER]);
        self::assertStringNotContainsString('hunter2secret', $mysql->detail);
    }
}
