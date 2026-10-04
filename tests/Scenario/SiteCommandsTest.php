<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Tests\Support\FakeGitHub;

/**
 * The M5 site commands (§10.2) through the real CLI: env, artisan, down / up,
 * php, node, key and logs.
 */
final class SiteCommandsTest extends DeployScenario
{
    private ?FakeGitHub $github = null;

    protected function tearDown(): void
    {
        $this->github?->stop();
        parent::tearDown();
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function cli(array $args, ?string $stdin = null): array
    {
        return $this->runCli($args, $stdin, 120);
    }

    /**
     * @covers-req ENV-03
     * @covers-req ENV-05
     * @covers-req ENV-06
     * @covers-req ENV-08
     */
    public function testEnvCommand(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $live = basename($this->liveDir());

        $list = $this->cli(['env', self::SITE, 'list']);
        $this->assertExit(0, $list);
        self::assertStringContainsString('APP_NAME', $list['stdout']);
        self::assertStringNotContainsString('supersecretpassword', $list['stdout']);
        self::assertStringContainsString('••••', $list['stdout']);
        self::assertStringContainsString('supersecretpassword', $this->cli(['env', self::SITE, 'list', '--reveal'])['stdout']);

        $calls = count($this->artisanCalls());
        $set = $this->cli(['env', self::SITE, 'set', 'MAIL_HOST=smtp.example.test', '--apply']);
        $this->assertExit(0, $set);
        self::assertStringContainsString("MAIL_HOST=smtp.example.test\n", (string) file_get_contents($this->siteFiles . '/shared/.env'));
        self::assertSame(0600, fileperms($this->siteFiles . '/shared/.env') & 0777);
        $backups = glob($this->siteFiles . '/shared/env-backups/.env.*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame(0600, fileperms($backups[0]) & 0777);
        $optimize = array_values(array_filter(array_slice($this->artisanCalls(), $calls), static fn ($c) => $c['cmd'] === 'optimize'));
        self::assertSame($live, $optimize[0]['release'] ?? null, 'ENV-08: optimize in the live release');
        $entry = $this->history()[1];
        self::assertSame(['env-change', ['set MAIL_HOST']], [$entry['action'], $entry['notes']]);

        $this->assertExit(0, $this->cli(['env', self::SITE, 'set', 'MAIL_PASSWORD=-'], "pa\$\$ word\n"));
        self::assertStringContainsString("MAIL_PASSWORD='pa\$\$ word'", (string) file_get_contents($this->siteFiles . '/shared/.env'));
        self::assertSame("pa\$\$ word\n", $this->cli(['env', self::SITE, 'get', 'MAIL_PASSWORD'])['stdout']);

        $unset = $this->cli(['env', self::SITE, 'unset', 'MAIL_HOST']);
        $this->assertExit(0, $unset);
        self::assertStringContainsString('cpdeploy env shop apply', $unset['stdout'], 'no --apply without a terminal: a hint');
        self::assertStringNotContainsString('MAIL_HOST', (string) file_get_contents($this->siteFiles . '/shared/.env'));

        $restoreList = $this->cli(['env', self::SITE, 'restore']);
        $oldest = trim((string) array_slice(explode("\n", trim($restoreList['stdout'])), -1)[0]);
        $this->assertExit(2, $this->cli(['env', self::SITE, 'restore', $oldest]));
        $this->assertExit(0, $this->cli(['env', self::SITE, 'restore', $oldest, '--yes']));
        self::assertStringNotContainsString('MAIL_', (string) file_get_contents($this->siteFiles . '/shared/.env'));

        $this->assertExit(2, $this->cli(['env', self::SITE, 'set', 'BAD=it\'s $x']));
        $this->assertExit(2, $this->cli(['env', self::SITE, 'get', 'NOPE']));
    }

    /**
     * @covers-req LAR-05
     * @covers-req LAR-08
     * @covers-req INV-03
     * @covers-req PHP-07
     */
    public function testArtisanDownAndUp(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $live = basename($this->liveDir());

        $status = $this->cli(['artisan', self::SITE, '--', 'migrate:status']);
        $this->assertExit(0, $status);
        self::assertStringContainsString('No pending migrations', $status['stdout']);
        $last = array_slice($this->artisanCalls(), -1)[0];
        self::assertSame(['migrate:status', $live, 'ea-php82'], [$last['cmd'], $last['release'], $last['php']]);

        $wipe = $this->cli(['artisan', self::SITE, '--', 'db:wipe']);
        $this->assertExit(2, $wipe);
        self::assertStringContainsString('can destroy data: this needs --yes', $wipe['stderr']);
        self::assertNotSame('db:wipe', array_slice($this->artisanCalls(), -1)[0]['cmd']);

        $down = $this->cli(['down', self::SITE, '--secret=let-me-in']);
        $this->assertExit(0, $down);
        self::assertStringContainsString('Bypass: https://' . self::DOMAIN . '/let-me-in', $down['stdout']);
        self::assertFileExists($this->liveDir() . '/storage/framework/down');
        self::assertContains('--secret=let-me-in', array_slice($this->artisanCalls(), -1)[0]['args']);
        self::assertTrue(json_decode($this->cli(['status', self::SITE, '--json'])['stdout'], true)['sites'][0]['maintenance']);

        $this->assertExit(0, $this->cli(['up', self::SITE]));
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');

        $static = $this->cli(['down', 'nosuchsite']);
        self::assertNotSame(0, $static['exit']);
    }

    /**
     * §9.5.4 through `cpdeploy php`: show, save only, switch now (+ HTTP-04 probe).
     *
     * @covers-req PHP-04
     * @covers-req HTTP-04
     * @covers-req SEC-09
     */
    public function testPhpCommand(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        $show = $this->cli(['php', self::SITE]);
        $this->assertExit(0, $show);
        self::assertStringContainsString('Site setting:  PHP 8.2 (ea)', $show['stdout']);
        self::assertStringContainsString('Domain now:    ea-php82 (MultiPHP)', $show['stdout']);
        self::assertStringContainsString('8.3 (ea)', $show['stdout']);

        $this->assertExit(3, $this->cli(['php', self::SITE, '9.9', '--no-redeploy'])); // E_PHP_MISSING
        $this->assertExit(2, $this->cli(['php', self::SITE, 'eight']));

        $save = $this->cli(['php', self::SITE, '8.3', '--no-redeploy']);
        $this->assertExit(0, $save);
        self::assertSame('8.3', $this->site()['php']['version']);
        self::assertSame('php-change', $this->history()[1]['action']);
        self::assertStringContainsString('It takes effect at the next deploy', $save['stdout']);

        $before = count($this->uapiCalls());
        $now = $this->cli(['php', self::SITE, 'ea-php83', '--no-redeploy', '--switch-now']);
        $this->assertExit(0, $now);
        $sets = array_values(array_filter(array_slice($this->uapiCalls(), $before), static fn ($c) => $c['call'] === 'LangPHP::php_set_vhost_versions'));
        self::assertSame('ea-php83', $sets[0]['args']['version'] ?? null);
        self::assertMatchesRegularExpression('/Served PHP: \d+\.\d+\.\d+/', $now['stdout'], 'HTTP-04 probe answered');
        self::assertSame([], glob($this->liveDir() . '/public/.cpd-probe-*') ?: [], 'the probe file is removed');
    }

    /**
     * `php <site> <v> --redeploy --yes`: the new PHP is deployed with Composer forced.
     *
     * @covers-req PLN-02
     */
    public function testPhpRedeploy(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        $r = $this->cli(['php', self::SITE, '8.3', '--redeploy', '--yes']);

        $this->assertExit(0, $r);
        $release = $this->releases()[basename($this->liveDir())];
        self::assertSame('8.3.20', $release['php']['version']);
        self::assertTrue($release['composer']['ran']);
    }

    /**
     * @covers-req NODE-01
     */
    public function testNodeCommand(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $show = $this->cli(['node', self::SITE]);
        self::assertStringContainsString('Setting:      auto', $show['stdout']);
        self::assertStringContainsString('Live release: 20.19.5', $show['stdout']);

        $this->assertExit(0, $this->cli(['node', self::SITE, '20']));
        self::assertSame('20', (string) $this->site()['node']['version']);
        self::assertSame('node-change', $this->history()[1]['action']);
        self::assertSame(['node auto → 20'], $this->history()[1]['notes']);
    }

    /**
     * S-32: key rotation with a token — the new key is registered read-only and
     * tested, the old one removed on GitHub, the id updated.
     *
     * @covers-req GIT-18
     * @covers-req GIT-06
     */
    public function testKeyShowTestAndRotate(): void
    {
        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            self::markTestSkipped('ssh-keygen is not installed');
        }
        $token = 'github_pat_' . str_repeat('a', 40);
        $this->github = new FakeGitHub($this->tmp . '/github', [
            'tokens' => [$token => ['login' => 'jay']],
            'repos' => [['full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'main', 'updated_at' => null, 'branches' => ['main']]],
        ]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
        $services = $this->services();
        $services->tokens()->set($token);
        $services->deployKeys()->generate(self::SITE, 'server1');
        $oldId = $services->deployKeys()->register($services->github(), $services->sites()->load(self::SITE)->repo(), self::SITE, 'tester', 'server1');
        $this->writeSite(['repo' => ['deploy_key_id' => $oldId]]);
        $oldPublic = (string) file_get_contents($this->home . '/.ssh/cpdeploy_' . self::SITE . '.pub');

        $show = $this->cli(['key', self::SITE]);
        $this->assertExit(0, $show);
        self::assertStringContainsString(trim($oldPublic), $show['stdout']);
        self::assertStringContainsString("key id {$oldId}", $show['stdout']);
        $test = $this->cli(['key', self::SITE, 'test']);
        $this->assertExit(0, $test);
        self::assertStringContainsString('can read the repository (1 branch)', $test['stdout']);

        $rotate = $this->cli(['key', self::SITE, 'rotate']);

        $this->assertExit(0, $rotate);
        $newPublic = (string) file_get_contents($this->home . '/.ssh/cpdeploy_' . self::SITE . '.pub');
        self::assertNotSame($oldPublic, $newPublic);
        $newId = $this->site()['repo']['deploy_key_id'];
        self::assertIsInt($newId);
        self::assertNotSame($oldId, $newId);
        $keys = $this->github->state()['keys']['acme/shop'] ?? [];
        self::assertSame([$newId], array_values(array_map(static fn ($k) => $k['id'], $keys)), 'only the new key is left on GitHub');
        self::assertTrue($keys[array_key_first($keys)]['read_only']);
        self::assertSame(['key-rotate', 'success'], [$this->history()[0]['action'], $this->history()[0]['result']]);
    }

    /**
     * §9.5.12 through `cpdeploy logs`.
     *
     * @covers-req LOG-01
     */
    public function testLogsCommand(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->assertExit(2, $this->runCli(['env', self::SITE, 'get', 'NOPE']));
        $this->assertExit(0, $this->deploy(['--force', '--yes']));

        $list = $this->cli(['logs', self::SITE]);
        $this->assertExit(0, $list);
        self::assertSame(2, preg_match_all('/\bdeploy\b.*success/', $list['stdout']));

        $json = json_decode($this->cli(['logs', self::SITE, '--json'])['stdout'], true);
        self::assertSame(1, $json['schema']);
        self::assertCount(2, $json['entries']);

        $last = $this->cli(['logs', self::SITE, '--last', '--tail=2']);
        $this->assertExit(0, $last);
        self::assertStringContainsString('result: success, exit code 0', $last['stdout']);

        $byRelease = $this->cli(['logs', self::SITE, basename($this->liveDir()), '--tail=1']);
        $this->assertExit(0, $byRelease);
        self::assertStringContainsString('result: success', $byRelease['stdout']);
        self::assertStringContainsString('has no failed operations', $this->cli(['logs', self::SITE, '--failed'])['stdout']);
        $this->assertExit(2, $this->cli(['logs', self::SITE, 'nope']));
    }
}
