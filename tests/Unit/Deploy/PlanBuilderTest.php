<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Deploy;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Deploy\ChangeSet;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Deploy\DeployPlan;
use Cpdeploy\Deploy\PlanBuilder;
use Cpdeploy\Deploy\Release;
use Cpdeploy\Git\Commit;
use Cpdeploy\Laravel\MigrationCheck;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\MemoryReporter;
use Cpdeploy\Ui\NonInteractiveAsker;
use Cpdeploy\Ui\ScriptedAsker;

/**
 * @covers-req PLN-01
 * @covers-req PLN-02
 * @covers-req PLN-03
 * @covers-req PLN-04
 * @covers-req PLN-05
 * @covers-req NI-02
 */
final class PlanBuilderTest extends TestCase
{
    private string $liveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveDir = $this->tmp . '/live';
        mkdir($this->liveDir . '/vendor', 0777, true);
        mkdir($this->liveDir . '/public/build', 0777, true);
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $changes ChangeSet overrides
     */
    private function ctx(
        array $site = [],
        array $changes = [],
        ?DeployFlags $flags = null,
        ?Asker $asker = null,
        bool $first = false,
        bool $composer = true,
        string $livePhp = '8.2.31',
    ): DeployContext {
        $config = new SiteConfig(array_replace_recursive(
            SiteSchema::withDefaults(['name' => 'shop', 'domain' => ['name' => 'shop.example.test']], (new Presets())->for('laravel')),
            $site,
        ));
        $ctx = new DeployContext($config, 'main', $flags ?? new DeployFlags(), $this->services()->paths(), GlobalConfig::defaults(), new MemoryReporter(), $asker ?? new ScriptedAsker());
        $ctx->commit = new Commit(str_repeat('a', 40), 'aaaaaaa', 'Dev', 'dev@example.test', 1_700_000_000, 'Change');
        $ctx->info = new ProjectInfo(
            'laravel',
            $composer ? ['require' => ['php' => '^8.2']] : null,
            $composer ? ['packages' => []] : null,
            ['scripts' => ['build' => 'vite build']],
            '12.31.0',
            true,
            false,
        );
        $ctx->php = new PhpInstall('ea', '8.2.31', '/opt/cpanel/ea-php82/root/usr/bin/php');
        $ctx->live = $first ? null : new Release('20260928-181002', $this->liveDir, ['php' => ['version' => $livePhp]]);
        $values = $changes + [
            'composerChanged' => false,
            'lockDiff' => ComposerInspector::lockDiff(null, null),
            'added' => [],
            'modified' => [],
            'deleted' => [],
            'nodeChanged' => false,
            'paths' => ['app/Models/User.php'],
        ];
        $ctx->changes = new ChangeSet(
            $first ? null : str_repeat('b', 40),
            str_repeat('a', 40),
            [],
            1,
            0,
            false,
            false,
            $values['composerChanged'],
            $values['lockDiff'],
            $values['added'],
            $values['modified'],
            $values['deleted'],
            $values['nodeChanged'],
            false,
            false,
            $first ? [] : $values['paths'],
        );

        return $ctx;
    }

    public function testComposerRows(): void
    {
        $planner = new PlanBuilder();

        $plan = $planner->build($this->ctx(composer: false));
        self::assertSame(DeployPlan::COMPOSER_NONE, $plan->composer);

        $plan = $planner->build($this->ctx(['steps' => ['composer_install' => 'every']]));
        self::assertSame([DeployPlan::COMPOSER_INSTALL, 'every deploy'], [$plan->composer, $plan->composerReason]);

        $plan = $planner->build($this->ctx(first: true, asker: new ScriptedAsker([['Migrations', 'yes']])));
        self::assertSame([DeployPlan::COMPOSER_INSTALL, 'first deploy'], [$plan->composer, $plan->composerReason]);

        rmdir($this->liveDir . '/vendor');
        $plan = $planner->build($this->ctx());
        self::assertSame([DeployPlan::COMPOSER_INSTALL, 'no vendor to reuse'], [$plan->composer, $plan->composerReason]);
        try {
            $planner->build($this->ctx(flags: new DeployFlags(composer: 'no')));
            self::fail('--composer=no must be refused without vendor/');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::USAGE, $e->errorCode);
            self::assertSame(2, $e->exitCode());
        }
        mkdir($this->liveDir . '/vendor');

        // PHP changed: shown, default install; Skip needs a second confirmation.
        $asker = new ScriptedAsker([['PHP changed 8.1 → 8.2', 'reuse'], ['Reuse vendor/', false]]);
        $plan = $planner->build($this->ctx(asker: $asker, livePhp: '8.1.30'));
        self::assertSame(DeployPlan::COMPOSER_INSTALL, $plan->composer);

        $asker = new ScriptedAsker([['composer.lock changed: 1 added', 'install']]);
        $plan = $planner->build($this->ctx([], [
            'composerChanged' => true,
            'lockDiff' => ComposerInspector::lockDiff(null, ['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]),
        ], asker: $asker));
        self::assertSame([DeployPlan::COMPOSER_INSTALL, 'lock changed'], [$plan->composer, $plan->composerReason]);

        $asker = new ScriptedAsker([['unchanged', 'reuse']]);
        $plan = $planner->build($this->ctx(asker: $asker));
        self::assertSame([DeployPlan::COMPOSER_REUSE, 'no changes'], [$plan->composer, $plan->composerReason]);

        $plan = $planner->build($this->ctx(flags: new DeployFlags(composer: 'yes')));
        self::assertSame([DeployPlan::COMPOSER_INSTALL, '--composer=yes'], [$plan->composer, $plan->composerReason]);

        try {
            $planner->build($this->ctx(['steps' => ['composer_install' => 'off']]));
            self::fail('composer_install: off with a composer.json is invalid (VAL-07)');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CONFIG_INVALID, $e->errorCode);
        }
    }

    public function testMigrationRows(): void
    {
        $planner = new PlanBuilder();
        $flags = new DeployFlags(composer: 'no');

        self::assertSame(DeployPlan::MIGRATE_NONE, $planner->build($this->ctx(['steps' => ['migrate' => 'off']], ['added' => ['x']], $flags))->migrate);
        self::assertSame(DeployPlan::MIGRATE_AUTO, $planner->build($this->ctx(['steps' => ['migrate' => 'every']], [], $flags))->migrate);

        $asker = new ScriptedAsker([['Migrations: 1 new', 'yes']]);
        $plan = $planner->build($this->ctx([], ['added' => ['2026_x']], $flags, $asker));
        self::assertSame(DeployPlan::MIGRATE_YES, $plan->migrate);
        self::assertSame(['2026_x'], $plan->migrationsExpected);

        $asker = new ScriptedAsker([['1 still pending from before', 'no']]);
        $plan = $planner->build($this->ctx([], [], $flags, $asker), new MigrationCheck(true, ['2025_old']));
        self::assertSame([DeployPlan::MIGRATE_NO, 'skipped'], [$plan->migrate, $plan->migrateReason]);

        // Only modified: no question, a warning.
        $ctx = $this->ctx([], ['modified' => ['2026_x']], $flags);
        self::assertSame(DeployPlan::MIGRATE_NONE, $planner->build($ctx)->migrate);
        self::assertStringContainsString("Changed migration files don't re-run", implode(' ', $ctx->warnings));

        self::assertSame(DeployPlan::MIGRATE_YES, $planner->build($this->ctx([], [], new DeployFlags(composer: 'no', migrate: 'yes')))->migrate);
    }

    public function testSeedRows(): void
    {
        $planner = new PlanBuilder();
        $flags = new DeployFlags(composer: 'no');

        self::assertFalse($planner->build($this->ctx([], [], $flags))->seed);
        $first = $planner->build($this->ctx(['steps' => ['seed' => 'first']], [], new DeployFlags(migrate: 'yes'), first: true));
        self::assertSame([true, 'first deploy'], [$first->seed, $first->seedReason]);
        self::assertFalse($planner->build($this->ctx(['steps' => ['seed' => 'first']], [], $flags))->seed);
        $asked = $planner->build($this->ctx(['steps' => ['seed' => 'ask']], [], $flags, new ScriptedAsker([['Seed', 'yes']])));
        self::assertTrue($asked->seed);
        self::assertTrue($planner->build($this->ctx([], [], new DeployFlags(composer: 'no', seed: 'yes')))->seed);
    }

    public function testFrontendRows(): void
    {
        $planner = new PlanBuilder();
        $flags = new DeployFlags(composer: 'no');

        self::assertSame([DeployPlan::BUILD_RUN, 'every deploy'], [$planner->build($this->ctx([], [], $flags))->build, $planner->build($this->ctx([], [], $flags))->buildReason]);
        self::assertSame(DeployPlan::BUILD_NONE, $planner->build($this->ctx(['steps' => ['frontend_build' => 'off']], [], $flags))->build);
        self::assertSame(DeployPlan::BUILD_REUSE, $planner->build($this->ctx([], [], new DeployFlags(composer: 'no', skipBuild: true)))->build);

        $ask = ['steps' => ['frontend_build' => 'ask']];
        $relevant = new ScriptedAsker([['files changed', 'build']]);
        self::assertSame(DeployPlan::BUILD_RUN, $planner->build($this->ctx($ask, [], $flags, $relevant))->build);
        $quiet = new ScriptedAsker([['no frontend changes', 'reuse']]);
        self::assertSame(DeployPlan::BUILD_REUSE, $planner->build($this->ctx($ask, ['paths' => ['database/seeders/X.php']], $flags, $quiet))->build);

        rmdir($this->liveDir . '/public/build');
        self::assertSame([DeployPlan::BUILD_RUN, 'nothing to reuse'], [
            $planner->build($this->ctx($ask, [], $flags))->build,
            $planner->build($this->ctx($ask, [], $flags))->buildReason,
        ]);
    }

    /**
     * @covers-req NI-02
     * @covers-req CLI-02
     */
    public function testNonInteractiveNeedsFlagsOrYes(): void
    {
        $planner = new PlanBuilder();
        $changes = ['composerChanged' => true, 'added' => ['2026_x']];
        try {
            $planner->build($this->ctx(['steps' => ['seed' => 'ask']], $changes, asker: new NonInteractiveAsker()));
            self::fail('Expected E_NEEDS_ANSWER');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::NEEDS_ANSWER, $e->errorCode);
            self::assertSame(2, $e->exitCode());
            self::assertSame('This needs answers: --composer=yes|no --migrate=yes|no --seed=yes|no (or --yes for the defaults)', $e->getMessage());
        }

        $plan = $planner->build($this->ctx([], $changes, new DeployFlags(yes: true), new NonInteractiveAsker(true)));
        self::assertSame(DeployPlan::COMPOSER_INSTALL, $plan->composer);
        self::assertSame(DeployPlan::MIGRATE_YES, $plan->migrate);

        $ctx = $this->ctx([], [], new DeployFlags(composer: 'no'), new NonInteractiveAsker());
        $ctx->plan = $planner->build($ctx);
        try {
            $planner->confirm($ctx);
            self::fail('Confirmation needs --yes (NI-03)');
        } catch (CpdeployException $e) {
            self::assertStringContainsString('--yes', $e->getMessage());
        }
    }
}
