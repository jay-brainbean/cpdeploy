<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Menus\MainMenu;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\Theme;
use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The menus (§9.2, §9.4, §9.5) driven in-process by a ScriptedAsker (§17 M5:
 * every menu path is exercised). Answers are [label fragment, value] pairs, so
 * a changed question order fails loudly.
 */
final class MenuTest extends DeployScenario
{
    private string $paged;

    protected function setUp(): void
    {
        parent::setUp();
        // The pager and the editor are fakes: `less` copies what it shows to a file,
        // the editor appends a line.
        $this->paged = $this->tmp . '/paged.txt';
        $this->env['CPD_PAGED'] = $this->paged;
        $this->fakeBin('less', "#!/bin/sh\ncat \"\$2\" >> \"\$CPD_PAGED\"\n");
        $this->fakeBin('fake-editor', "#!/bin/sh\nprintf '%s\\n' \"\$CPD_EDIT_LINE\" >> \"\$1\"\n");
        $this->env['EDITOR'] = 'fake-editor';
    }

    /**
     * Runs the main menu with $answers; every answer must be used.
     *
     * @param list<mixed> $answers
     */
    private function menu(array $answers, bool $firstRun = false): string
    {
        $asker = new ScriptedAsker($answers);
        $output = new BufferedOutput();
        $services = $this->services();
        $theme = new Theme(true);
        $ctx = new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme);
        try {
            (new MainMenu($ctx))->run($firstRun);
        } catch (LogicException $e) {
            self::fail($e->getMessage() . "\n\nScreen so far:\n" . $output->fetch() . "\nAsked:\n" . implode("\n", array_map(static fn ($q) => $q['type'] . ': ' . $q['label'], $asker->asked)));
        }
        $text = $output->fetch();
        self::assertSame(0, $asker->remaining(), "Unused answers. Screen:\n" . $text);

        return $text;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function main(string $choice): array
    {
        return ['What would you like to do?', $choice];
    }

    /**
     * §9.2 and §9.4: deploy from the main menu (one site → straight to it), the
     * result, then Logs & history across all sites with a log opened in the pager.
     *
     * @covers-req UIG-04
     * @covers-req UIG-05
     */
    public function testDeployFromTheMainMenuThenLogs(): void
    {
        $screen = $this->menu([
            $this->main('deploy'),
            ['Migrations', 'yes'],
            ['Deploy', 'deploy'],
            $this->main('logs'),
            ['Open a log', 'e0'],
            ['Open a log', MenuContext::BACK],
            $this->main('quit'),
        ]);

        self::assertStringContainsString('cpdeploy · shop · Deploy', $screen);
        self::assertStringContainsString('Live in', $screen);
        self::assertStringContainsString('cpdeploy · Logs & history', $screen);
        self::assertNotNull($this->current());
        self::assertStringContainsString('result: success', (string) @file_get_contents($this->paged));
        self::assertMatchesRegularExpression('/shop\s+shop\.example\.test\s+[0-9a-f]{7}\s+✓ deployed/', $screen, 'the site table after the deploy');
    }

    /**
     * S-26: APP_KEY empty; on the deploy screen the answer is "Generate APP_KEY
     * now": the key is written (with a backup) and the deploy continues.
     *
     * @covers-req LAR-06
     * @covers-req ENV-06
     */
    public function testGenerateAppKeyInlineAndDeploy(): void
    {
        $env = (string) file_get_contents($this->siteFiles . '/shared/.env');
        $this->writeEnv((string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=', $env));

        $screen = $this->menu([
            $this->main('deploy'),
            ['APP_KEY is empty', 'generate'],
            ['Migrations', 'yes'],
            ['Deploy', 'deploy'],
            $this->main('quit'),
        ]);

        self::assertStringContainsString('APP_KEY generated and saved to .env', $screen);
        // ENV-04: "=" isn't in the unquoted set, so the key is written in double quotes.
        self::assertMatchesRegularExpression('/^APP_KEY="base64:[A-Za-z0-9+\/=]{44}"$/m', (string) file_get_contents($this->siteFiles . '/shared/.env'));
        $backups = glob($this->siteFiles . '/shared/env-backups/.env.*') ?: [];
        self::assertCount(1, $backups);
        self::assertStringContainsString("APP_KEY=\n", (string) file_get_contents($backups[0]));
        $deploy = array_values(array_filter($this->history(), static fn ($e) => $e['action'] === 'deploy'))[0];
        self::assertSame('success', $deploy['result']);
        self::assertStringStartsWith('.env: APP_KEY generated', (string) array_values(array_filter($deploy['notes'], static fn ($n) => str_starts_with($n, '.env:')))[0]);
        self::assertNotNull($this->current());
    }

    /**
     * §9.4 failure screen: the build fails → View full log → Retry with changes…
     * (skip the frontend build) → the deploy succeeds with the reused build.
     *
     * @covers-req RTY-01
     */
    public function testBuildFailureRetryWithChanges(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Second version');
        $this->env['CPD_FAKE_NPM'] = 'fail';

        $screen = $this->menu([
            $this->main('deploy'),
            ['composer', 'reuse'],
            ['Deploy', 'deploy'],
            ['What next?', 'log'],
            ['What next?', 'changes'],
            ['Deploy with changes', ['skip_build', 'skip_optimize', 'skip_migrations']],
            ['composer', 'reuse'],
            ['Deploy', 'deploy'],
            $this->main('quit'),
        ]);

        self::assertStringContainsString('npm run build failed', $screen);
        self::assertStringContainsString('Rollup failed to resolve import', (string) @file_get_contents($this->paged));
        $live = $this->releases()[basename($this->liveDir())];
        self::assertSame('live', $live['status']);
        self::assertNotNull($live['build']['reused_from']);
    }

    /**
     * §9.5.1: Deploy with changes → Different branch, tag or commit… (a branch).
     *
     * @covers-req RTY-03
     */
    public function testDeployWithChangesToAnotherBranch(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->repo->git('checkout', '-q', '-b', 'feature');
        $this->change(['routes/web.php' => "<?php // feature\n"], 'Feature work');
        $this->repo->git('checkout', '-q', 'main');

        $this->menu([
            $this->main('manage'),
            ['Manage shop', 'changes'],
            ['Deploy with changes', ['ref', 'skip_composer', 'skip_build']],
            ['Deploy which branch, tag or commit?', 'feature'],
            ['Deploy', 'deploy'],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        $live = $this->releases()[basename($this->liveDir())];
        self::assertSame('feature', $live['ref']);
        self::assertSame('main', $this->site()['repo']['branch'], 'RTY-03: --ref does not change repo.branch');

        $contradiction = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'changes'],
            ['Deploy with changes', ['skip_composer', 'force_composer', 'skip_build']],
            ['Deploy with changes', []],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);
        self::assertStringContainsString("can't both be chosen", $contradiction);
    }

    /**
     * UIG-03: an interrupted operation (→ Recover now) and maintenance mode
     * (→ Turn off) are banners above the main menu.
     *
     * @covers-req UIG-03
     * @covers-req REC-01
     */
    public function testBannersRecoverAndTurnOffMaintenance(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->assertExit(0, $this->runCli(['down', self::SITE]));
        file_put_contents($this->siteDir . '/.deploy-state.json', (string) json_encode(['schema' => 1, 'operation' => 'deploy', 'pid' => 999999, 'phase' => 'planning']));

        $screen = $this->menu([
            ['What would you like to do?', 'recover:shop'],
            ['What would you like to do?', 'up:shop'],
            $this->main('quit'),
        ]);

        self::assertStringContainsString('⚠ interrupted, recover', $screen);
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json');
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');
        self::assertStringContainsString('⚠ maintenance on', $screen);
        self::assertStringContainsString('Recovered shop', $screen);
    }

    /**
     * §9.5.2, §9.5.3: Releases (details, protect, delete) and Roll back (picker).
     *
     * @covers-req RB-02
     * @covers-req RB-05
     */
    public function testReleasesAndRollback(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename($this->liveDir());
        $this->assertExit(0, $this->deploy(['--force', '--yes']));
        $second = basename($this->liveDir());
        $this->assertExit(0, $this->deploy(['--force', '--yes']));

        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'releases'],
            ['Choose a release', $first],
            ["Release {$first}", 'details'],
            ['Choose a release', $first],
            ["Release {$first}", 'protect'],
            ['Choose a release', $second],
            ["Release {$second}", 'delete'],
            ["Delete release {$second}?", true],
            ['Choose a release', MenuContext::BACK],
            ['Manage shop', 'rollback'],
            ['Roll back to which release?', $first],
            ['Roll back ' . self::DOMAIN, true],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        self::assertStringContainsString('deployed_by', $screen);
        self::assertTrue($this->releases()[$first]['protected']);
        self::assertArrayNotHasKey($second, $this->releases());
        self::assertSame('releases/' . $first, $this->current());
        self::assertStringContainsString('Rolled back to ' . $first, $screen);
    }

    /**
     * §9.5.4 and §9.5.5: PHP version (served-version probe; change without
     * redeploying, no domain switch) and Node version (Other…, Later).
     *
     * @covers-req HTTP-04
     */
    public function testPhpAndNodeMenus(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'php'],
            ['PHP version', 'probe'],
            ['PHP version', 'change'],
            ['Use which PHP?', 'ea-php83'],
            ['Redeploy now with PHP 8.3?', 'no'],
            ["Also switch the domain's PHP right now?", false],
            ['PHP version', MenuContext::BACK],
            ['Manage shop', 'node'],
            ['Build with which Node?', 'other'],
            ['Node version', '20'],
            ['Deploy now?', 'later'],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        self::assertMatchesRegularExpression('/Served PHP: \d+\.\d+\.\d+/', $screen);
        self::assertStringContainsString('Domain now:    ea-php82 (MultiPHP)', $screen);
        self::assertSame('8.3', $this->site()['php']['version']);
        self::assertSame('20', (string) $this->site()['node']['version']);
        self::assertSame(['php-change', 'node-change'], array_slice(array_column($this->history(), 'action'), -2));
    }

    /**
     * §9.5.6: the deploy-steps editor.
     *
     * @covers-req VAL-07
     */
    public function testStepsEditor(): void
    {
        $this->menu([
            $this->main('manage'),
            ['Manage shop', 'steps'],
            ['Select a step', 'step:migrate'],
            ['php artisan migrate --force', 'every'],
            ['Select a step', 'add'],
            ['Name', 'Warm cache'],
            ['Command', 'php artisan cache:warm'],
            ['When in the deploy?', 'after_activate'],
            ['How often?', 'every'],
            ['If it fails', 'warn'],
            ['Select a step', 'custom:0'],
            ['Warm cache', 'ask'],
            ['Select a step', 'keep'],
            ['Keep how many releases?', '3'],
            ['Select a step', 'health'],
            ['Check the site after each go-live?', true],
            ['Path to request', '/up'],
            ['Select a step', 'done'],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        $site = $this->site();
        self::assertSame('every', $site['steps']['migrate']);
        self::assertSame([['name' => 'Warm cache', 'run' => 'php artisan cache:warm', 'phase' => 'after_activate', 'when' => 'ask', 'timeout' => 300, 'on_error' => 'warn']], $site['custom_commands']);
        self::assertSame(3, $site['releases']['keep']);
        self::assertSame('/up', $site['health_check']['path']);

        $this->menu([
            $this->main('manage'),
            ['Manage shop', 'steps'],
            ['Select a step', 'custom:0'],
            ['Warm cache', 'remove'],
            ['Select a step', 'done'],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);
        self::assertSame([], $this->site()['custom_commands']);
    }

    /**
     * §9.5.7: every Environment action, each followed by ENV-08.
     *
     * @covers-req ENV-05
     * @covers-req ENV-06
     * @covers-req ENV-07
     * @covers-req ENV-08
     */
    public function testEnvironmentMenu(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->env['CPD_EDIT_LINE'] = 'EDITED_IN_EDITOR=1';

        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'env'],
            ['Environment', 'view'],
            ['Environment', 'reveal'],
            ['Show secret values', true],
            ['Environment', 'edit'],
            ['Edit which variable?', 'APP_NAME'],
            ['New value of APP_NAME', 'Shop 2'],
            ['Apply to the live site now?', false],
            ['Environment', 'add'],
            ['Name', 'MAIL_PASSWORD'],
            ['Value of MAIL_PASSWORD', 'hunter2hunter2'],
            ['Apply to the live site now?', false],
            ['Environment', 'remove'],
            ['Remove which variable?', 'APP_URL'],
            ['Remove APP_URL?', true],
            ['Apply to the live site now?', false],
            ['Environment', 'editor'],
            ['Apply to the live site now?', true],
            ['Environment', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);
        self::assertStringContainsString("EDITED_IN_EDITOR=1\n", (string) file_get_contents($this->siteFiles . '/shared/.env'));
        self::assertStringContainsString("MAIL_PASSWORD=hunter2hunter2\n", (string) file_get_contents($this->siteFiles . '/shared/.env'));

        $backups = array_map('basename', glob($this->siteFiles . '/shared/env-backups/.env.*') ?: []);
        sort($backups);
        $screen .= $this->menu([
            $this->main('manage'),
            ['Manage shop', 'env'],
            ['Environment', 'restore'],
            ['Restore which backup?', $backups[0]],
            ['Restore', true],
            ['Apply to the live site now?', false],
            ['Environment', 'apply'],
            ['Environment', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        $env = (string) file_get_contents($this->siteFiles . '/shared/.env');
        self::assertStringContainsString('supersecretpassword', $screen, 'Reveal shows secrets');
        self::assertSame(1, substr_count($screen, 'supersecretpassword'), 'View masks them');
        self::assertStringContainsString('APP_URL=', $env, 'the oldest backup (before any change) was restored');
        self::assertStringNotContainsString('EDITED_IN_EDITOR', $env);
        self::assertCount(5, glob($this->siteFiles . '/shared/env-backups/.env.*') ?: []);
        self::assertStringNotContainsString('hunter2hunter2', $screen);
    }

    /**
     * §9.5.8: every Laravel tool, in the live release with its PHP.
     *
     * @covers-req LAR-05
     * @covers-req LAR-07
     * @covers-req LAR-08
     */
    public function testLaravelToolsMenu(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $live = basename($this->liveDir());
        $this->change(['database/migrations/2026_02_01_000000_add_coupons.php' => "<?php // coupons\n"], 'A migration');
        // Put the new migration file into the live release, as if deployed without migrating.
        copy($this->repo->work . '/database/migrations/2026_02_01_000000_add_coupons.php', $this->liveDir() . '/database/migrations/2026_02_01_000000_add_coupons.php');
        @mkdir($this->siteFiles . '/shared/storage/logs', 0755, true); // shared by the deploy already
        file_put_contents($this->siteFiles . '/shared/storage/logs/laravel.log', "[2026-09-29] production.ERROR: Something broke\n");

        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'laravel'],
            ['Laravel tools', 'artisan'],
            ['php artisan', 'migrate:status'],
            ['Laravel tools', 'artisan'],
            ['php artisan', 'db:wipe'],
            ['Type the site name', 'wrong'],
            ['Laravel tools', 'down'],
            ['Retry-After seconds', '30'],
            ['Bypass secret', 'open-sesame'],
            ['Laravel tools', 'up'],
            ['Laravel tools', 'optimize'],
            ['Laravel tools', 'clear'],
            ['Clear the caches?', true],
            ['Laravel tools', 'status'],
            ['Laravel tools', 'migrate'],
            ['Run them now?', true],
            ['Laravel tools', 'tinker'],
            ['Laravel tools', 'log'],
            ['Laravel tools', 'cron'],
            ['Laravel tools', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        $calls = array_values(array_filter($this->artisanCalls(), static fn ($c) => $c['release'] === $live));
        $commands = array_column($calls, 'cmd');
        foreach (['migrate:status', 'down', 'up', 'optimize', 'optimize:clear', 'migrate', 'tinker'] as $cmd) {
            self::assertContains($cmd, $commands, "{$cmd} ran in the live release");
        }
        self::assertNotContains('db:wipe', $commands, 'LAR-08: the name did not match');
        $down = array_values(array_filter($calls, static fn ($c) => $c['cmd'] === 'down'))[0] ?? ['args' => []];
        self::assertContains('--secret=open-sesame', $down['args']);
        self::assertStringContainsString('https://' . self::DOMAIN . '/open-sesame', $screen);
        self::assertStringContainsString('Pending: 2026_02_01_000000_add_coupons', $screen);
        self::assertStringContainsString('1 migration ran', $screen);
        self::assertStringContainsString('Something broke', (string) @file_get_contents($this->paged));
        self::assertStringContainsString($this->siteFiles . '/current/artisan schedule:run >> /dev/null 2>&1', $screen);
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');
    }

    /**
     * §9.5.9 Branch, §9.5.10 Deploy key (show, test), §9.5.11 Composer credentials,
     * §9.5.12 Logs & history, §9.5.13 Site info, and Remove site.
     *
     * @covers-req CMP-06
     * @covers-req GIT-06
     */
    public function testBranchKeyComposerLogsInfoAndRemove(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->repo->git('checkout', '-q', '-b', 'release');
        $this->repo->push();
        $this->repo->git('checkout', '-q', 'main');
        $this->env['CPD_EDIT_LINE'] = '';

        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'branch'],
            ['Deploy which branch?', 'release'],
            ['Deploy now?', 'later'],
            ['Manage shop', 'key'],
            ['Deploy key', 'show'],
            ['Deploy key', 'test'],
            ['Deploy key', MenuContext::BACK],
            ['Manage shop', 'composer'],
            ['Composer credentials', 'edit'],
            ['Composer credentials', 'remove'],
            ['Remove auth.json?', true],
            ['Composer credentials', MenuContext::BACK],
            ['Manage shop', 'logs'],
            ['Open a log', 'e0'],
            ['Open a log', 'filter'],
            ['Open a log', MenuContext::BACK],
            ['Manage shop', 'info'],
            ['Site info', 'sizes'],
            ['Site info', MenuContext::BACK],
            ['Manage shop', 'remove'],
            ['What should happen to', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        self::assertSame('release', $this->site()['repo']['branch']);
        self::assertStringContainsString('is missing — rotate it to create a new one', $screen);
        self::assertStringContainsString('can read the repository (2 branches)', $screen);
        self::assertFileDoesNotExist($this->siteFiles . '/shared/auth.json');
        self::assertStringContainsString('auth.json saved', $screen);
        self::assertStringContainsString('result: success', (string) @file_get_contents($this->paged));
        self::assertStringContainsString('No failed operations.', $screen);
        self::assertStringContainsString($this->docroot . ' → ' . $this->siteFiles . '/current/public', $screen);
        self::assertMatchesRegularExpression('/Releases\s+\d+(\.\d)? [KMG]B/', $screen);
        self::assertStringContainsString('cpdeploy · shop · Remove site', $screen);
        self::assertDirectoryExists($this->siteDir, 'Back at the first Remove site question removes nothing');
    }

    /**
     * UIG-06: while another operation holds the lock, entries that change the
     * site are marked busy and choosing one shows E_LOCKED.
     *
     * @covers-req UIG-06
     * @covers-req LCK-01
     */
    public function testBusySiteShowsLocked(): void
    {
        $services = $this->services();
        $lock = \Cpdeploy\Support\Lock::site($services->paths()->siteLock(self::SITE), self::SITE, 'deploy', 'tester', 'test', $services->clock());
        try {
            $screen = $this->menu([
                $this->main('manage'),
                ['Manage shop', 'node'],
                ['Build with which Node?', 'auto'],
                ['Manage shop', MenuContext::BACK],
                $this->main('quit'),
            ]);
        } finally {
            $lock->release();
        }

        self::assertStringContainsString('Another cpdeploy operation (deploy', $screen);
        self::assertStringContainsString('… deploying now', $screen);
    }

    /**
     * The remaining branches: PHP → redeploy now and → switch the domain now;
     * Node and Branch → Deploy now; Deploy key → Rotate (added by hand).
     *
     * @covers-req GIT-18
     * @covers-req PLN-02
     */
    public function testRedeployAndSwitchNowPaths(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        $this->menu([
            $this->main('manage'),
            ['Manage shop', 'php'],
            ['PHP version', 'change'],
            ['Use which PHP?', 'ea-php83'],
            ['Redeploy now with PHP 8.3?', 'yes'],
            ['Deploy', 'deploy'],
            ['PHP version', 'change'],
            ['Use which PHP?', 'ea-php82'],
            ['Redeploy now with PHP 8.2?', 'no'],
            ["Also switch the domain's PHP right now?", true],
            ['PHP version', MenuContext::BACK],
            ['Manage shop', 'node'],
            ['Build with which Node?', 'auto'],
            ['Deploy now?', 'deploy'],
            ['Nothing new on main', 'back'],
            ['Manage shop', 'branch'],
            ['Deploy which branch?', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);

        $live = $this->releases()[basename($this->liveDir())];
        self::assertSame('8.3.20', $live['php']['version'], 'redeployed with PHP 8.3');
        self::assertTrue($live['composer']['ran']);
        $sets = array_values(array_filter($this->uapiCalls(), static fn ($c) => $c['call'] === 'LangPHP::php_set_vhost_versions'));
        self::assertSame('ea-php82', end($sets)['args']['version'] ?? null, 'switched back to 8.2 right away');

        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            return;
        }
        $screen = $this->menu([
            $this->main('manage'),
            ['Manage shop', 'key'],
            ['Deploy key', 'rotate'],
            ['Create a new deploy key', true],
            ['Added it on GitHub', true],
            ['Deploy key', MenuContext::BACK],
            ['Manage shop', MenuContext::BACK],
            $this->main('quit'),
        ]);
        self::assertFileExists($this->home . '/.ssh/cpdeploy_' . self::SITE);
        self::assertStringContainsString('New deploy key in place and tested', $screen);
        self::assertStringContainsString('github.com/acme/shop/settings/keys', $screen);
        self::assertSame('key-rotate', array_slice($this->history(), -1)[0]['action'] ?? null);
    }
}
