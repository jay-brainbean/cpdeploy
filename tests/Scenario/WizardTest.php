<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\Theme;
use Cpdeploy\Wizard\AddSiteWizard;
use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * The add-site wizard (§9.3), `add --from` and the legacy import (§10.6),
 * driven in-process by a ScriptedAsker (S-29, S-30, S-31, S-35, S-39).
 */
final class WizardTest extends DeployScenario
{
    private const TOKEN = 'github_pat_good_token_123456';

    private ?FakeGitHub $github = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The scenario harness writes a site by hand; the wizard starts without one.
        exec('rm -rf ' . escapeshellarg($this->siteDir) . ' ' . escapeshellarg($this->siteFiles));
        $this->github = new FakeGitHub($this->tmp . '/github');
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
        $this->env['CPDEPLOY_NOW'] = '2026-09-29T12:00:00Z';
    }

    protected function tearDown(): void
    {
        $this->github?->stop();
        parent::tearDown();
    }

    /**
     * S-29: with a token, the whole Laravel setup with a new database.
     *
     * @covers-req WIZ-01
     * @covers-req WIZ-04
     * @covers-req DB-01
     * @covers-req ENV-09
     * @covers-req GIT-05
     */
    public function testTokenPathFullLaravelSetupWithNewDatabase(): void
    {
        $this->saveToken();
        $screen = $this->wizard([
            ...$this->pickFromList(),
            ...$this->middleSteps(),
            ['Where does the .env come from?', 'example'],
            ['APP_NAME', 'Shop'],
            ['APP_URL', null],
            ['Database', 'create'],
            ['Select a step to change when it runs', 'done'],
            ['Create the site?', 'create'],
        ]);

        self::assertStringContainsString('cpdeploy · Add a site · Step 1/10 · Repository', $screen);
        self::assertStringContainsString('Added read-only deploy key to acme/shop', $screen);
        self::assertStringContainsString('Detected: Laravel', $screen);
        self::assertStringContainsString('Site shop created', $screen);

        // site.yml
        $site = $this->site();
        self::assertSame('shop', $site['name']);
        self::assertSame('laravel', $site['type']);
        self::assertSame(['owner' => 'acme', 'name' => 'shop', 'branch' => 'main', 'transport' => 'ssh22', 'deploy_key_id' => 1000], $site['repo']);
        self::assertSame(self::DOMAIN, $site['domain']['name']);
        self::assertSame($this->docroot, $site['domain']['docroot']);
        self::assertSame('public', $site['domain']['web_dir']);
        self::assertSame('8.2', $site['php']['version']);
        self::assertSame(['created_by_cpdeploy' => true, 'name' => 'cpuser_shop', 'user' => 'cpuser_shop'], $site['database']);
        self::assertSame('2026-09-29T12:00:00Z', $site['created_at']);
        // LAY-04: the site's own folder is named after the domain, outside ~/cpdeploy.
        self::assertSame('cpdeploy_sites/' . self::DOMAIN, $site['site_dir']);
        self::assertSame(0711, fileperms($this->siteFiles) & 0777);
        self::assertSame(['shared'], array_values(array_diff(scandir($this->siteFiles) ?: [], ['.', '..'])));
        self::assertStringContainsString('~/cpdeploy_sites/' . self::DOMAIN . '  (current, releases, shared)', $screen);

        // The database and user, through cPanel (DB-01).
        $calls = array_column($this->uapiCalls(), 'args', 'call');
        self::assertSame('cpuser_shop', $calls['Mysql::create_database']['name']);
        self::assertSame('cpuser_shop', $calls['Mysql::create_user']['name']);
        $password = $calls['Mysql::create_user']['password'];
        self::assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z0-9]{32}$/', $password);
        self::assertSame('ALL PRIVILEGES', $calls['Mysql::set_privileges_on_database']['privileges'] ?? 'ALL PRIVILEGES');

        // shared/.env (ENV-09), 600.
        $env = (string) file_get_contents($this->siteFiles . '/shared/.env');
        self::assertSame(0600, fileperms($this->siteFiles . '/shared/.env') & 0777);
        foreach (['APP_NAME=Shop', 'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://' . self::DOMAIN, 'DB_CONNECTION=mysql', 'DB_DATABASE=cpuser_shop', 'DB_USERNAME=cpuser_shop', 'DB_PASSWORD=' . $password] as $line) {
            self::assertStringContainsString($line, $env);
        }
        self::assertMatchesRegularExpression('/^APP_KEY="?base64:[A-Za-z0-9+\/=]{44}"?$/m', $env);
        self::assertStringNotContainsString($password, $screen, 'SEC-07: the password is never shown');

        // The key, added read-only through the API; the mirror moved in; tmp cleaned.
        $keys = $this->github?->state()['keys']['acme/shop'] ?? [];
        self::assertCount(1, $keys);
        self::assertTrue($keys[0]['read_only']);
        self::assertFileExists($this->home . '/.ssh/cpdeploy_shop');
        self::assertDirectoryExists($this->siteDir . '/repo.git');
        self::assertSame([], glob($this->root . '/tmp/cpd-wizard-*') ?: []);
        self::assertSame(['site-create'], array_column($this->history(), 'action'));
    }

    /**
     * S-30: cancelling after the key was added deletes it locally and on GitHub.
     *
     * @covers-req WIZ-02
     * @covers-req WIZ-03
     */
    public function testCancelAfterTheKeyWasAddedRemovesIt(): void
    {
        $this->saveToken();
        $screen = $this->wizard([
            ...$this->pickFromList(),
            ['Project type', MenuContext::BACK],
            // Step 2 has nothing to ask again; at step 1 "<" goes back to picking the repo.
            ['Site name', '<'],
            ['How do you want to pick the repository?', 'cancel'],
            ['Cancel adding this site?', true],
            ['Remove the deploy key created for this site?', true],
        ]);

        self::assertStringContainsString('Nothing was added.', $screen);
        self::assertSame([], $this->github?->state()['keys']['acme/shop'] ?? []);
        self::assertContains('DELETE /repos/acme/shop/keys/1000', array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'], $this->github?->calls() ?? []));
        self::assertFileDoesNotExist($this->home . '/.ssh/cpdeploy_shop');
        self::assertFileDoesNotExist($this->home . '/.ssh/cpdeploy_shop.pub');
        self::assertDirectoryDoesNotExist($this->siteDir);
        self::assertSame([], glob($this->root . '/tmp/cpd-wizard-*') ?: []);
    }

    /**
     * S-31: Create fails at the site.yml write: the database and user are dropped
     * and the site folder removed; the key stays unless the user removes it.
     *
     * @covers-req WIZ-04
     */
    public function testCreateFailureUndoesTheDatabaseAndSiteFolder(): void
    {
        $this->saveToken();
        $this->env['CPDEPLOY_TEST_FAIL_CREATE'] = 'site.yml';
        $screen = $this->wizard([
            ...$this->pickFromList(),
            ...$this->middleSteps(),
            ['Where does the .env come from?', 'example'],
            ['APP_NAME', null],
            ['APP_URL', null],
            ['Database', 'create'],
            ['Select a step to change when it runs', 'done'],
            ['Create the site?', 'create'],
            ['Remove the deploy key created for this site?', false],
        ]);

        self::assertStringContainsString('Simulated failure at site.yml', $screen);
        self::assertStringContainsString('Removed the database and user created for this site', $screen);
        $calls = array_column($this->uapiCalls(), 'args', 'call');
        self::assertSame('cpuser_shop', $calls['Mysql::delete_database']['name']);
        self::assertSame('cpuser_shop', $calls['Mysql::delete_user']['name']);
        self::assertDirectoryDoesNotExist($this->siteDir);
        self::assertDirectoryDoesNotExist($this->siteFiles, 'LAY-04: the site\'s own folder goes too');
        self::assertFileExists($this->home . '/.ssh/cpdeploy_shop', 'the key is kept when the user says so');
        self::assertCount(1, $this->github?->state()['keys']['acme/shop'] ?? []);
        self::assertSame([], glob($this->root . '/tmp/cpd-wizard-*') ?: []);
    }

    /**
     * S-35: `add --from` without a token prints the key and exits 2; the same
     * command then creates the site (and, with deploy_now, deploys it).
     *
     * @covers-req GIT-17
     * @covers-req DB-03
     */
    public function testAddFromFileWithoutTokenPrintsTheKeyThenCompletes(): void
    {
        $file = $this->tmp . '/shop.yml';
        file_put_contents($file, Yaml::dump([
            'name' => self::SITE,
            'repo' => ['owner' => 'acme', 'name' => 'shop', 'branch' => 'main'],
            'domain' => ['name' => self::DOMAIN],
            'php' => ['version' => '8.2'],
            'health_check' => ['attempts' => 1, 'timeout' => 5],
            'steps' => ['migrate' => 'every'],
            'setup' => ['env' => 'example', 'app_url' => 'https://' . self::DOMAIN, 'database' => 'sqlite', 'deploy_now' => true],
        ], 4, 2));

        $first = $this->runCli(['add', '--from=' . $file]);
        $this->assertExit(2, $first);
        self::assertStringContainsString('Add this deploy key to acme/shop:', $first['stdout']);
        self::assertStringContainsString('https://github.com/acme/shop/settings/keys/new', $first['stdout']);
        self::assertStringContainsString('ssh-ed25519 ', $first['stdout']);
        self::assertStringContainsString('Then run the same command again', $first['stdout']);
        self::assertFileExists($this->home . '/.ssh/cpdeploy_shop');
        self::assertDirectoryDoesNotExist($this->siteDir);
        $key = (string) file_get_contents($this->home . '/.ssh/cpdeploy_shop');

        $second = $this->runCli(['add', '--from=' . $file], null, 180);
        $this->assertExit(0, $second);
        self::assertStringContainsString('Site shop created', $second['stdout']);
        self::assertSame($key, file_get_contents($this->home . '/.ssh/cpdeploy_shop'), 'the key is reused');
        $site = $this->site();
        self::assertNull($site['repo']['deploy_key_id']);
        self::assertSame('every', $site['steps']['migrate'], 'keys the wizard does not model are kept');
        self::assertArrayNotHasKey('setup', $site);
        $env = (string) file_get_contents($this->siteFiles . '/shared/.env');
        self::assertStringContainsString('DB_CONNECTION=sqlite', $env);
        self::assertStringContainsString('DB_DATABASE=' . $this->siteFiles . '/shared/database/database.sqlite', $env);
        self::assertStringContainsString('# DB_HOST=', $env);
        self::assertSame(0600, fileperms($this->siteFiles . '/shared/database/database.sqlite') & 0777);

        // deploy_now: the first deploy ran.
        self::assertNotNull($this->current());
        $this->assertInvariants();
        self::assertSame(['site-create', 'deploy'], array_column($this->history(), 'action'));
    }

    /**
     * `add` without a terminal points at --from; a bad setup key is refused.
     */
    public function testAddWithoutTerminalAndBadSetup(): void
    {
        $r = $this->runCli(['add']);
        $this->assertExit(2, $r);
        self::assertStringContainsString('cpdeploy add --from=<file.yml>', $r['stderr']);

        $file = $this->tmp . '/bad.yml';
        file_put_contents($file, "name: shop\nsetup:\n  datbase: create\n");
        $bad = $this->runCli(['add', '--from=' . $file]);
        self::assertNotSame(0, $bad['exit']);
        self::assertStringContainsString("unknown setup key 'datbase'", $bad['stderr']);
    }

    /**
     * S-39: a cpanel-git-setup.sh site is imported: answers pre-filled, the old
     * key copied, access OK, and after the first deploy the old cron line and
     * webhook file are offered for removal.
     *
     * @covers-req LEG-01
     * @covers-req LEG-02
     * @covers-req LEG-03
     * @covers-req LEG-04
     */
    public function testLegacyImport(): void
    {
        $legacy = $this->home . '/deployments/shop';
        mkdir($legacy . '/repo', 0755, true);
        exec('git -C ' . escapeshellarg($legacy . '/repo') . ' init -q && git -C ' . escapeshellarg($legacy . '/repo') . ' remote add origin git@github-shop:acme/shop.git');
        $hooks = $this->home . '/public_html';
        mkdir($hooks, 0755, true);
        file_put_contents($hooks . '/deploy-hook-shop-a1b2c3.php', "<?php // old webhook\n");
        file_put_contents($legacy . '/deploy.conf', implode("\n", [
            'BRANCH="main"',
            'DEST="' . $this->docroot . '"',
            'BUILD_CMD="npm ci && npm run build"',
            "POST_CMD='php artisan migrate --force'",
            'PHP_VERSION=8.3',
            'NODE_VERSION=auto',
            'HOOK_DIR=' . $hooks,
            '',
        ]));
        mkdir($this->home . '/.ssh', 0700, true);
        exec('ssh-keygen -q -t ed25519 -N "" -C old -f ' . escapeshellarg($this->home . '/.ssh/deploy_shop'));
        $oldKey = (string) file_get_contents($this->home . '/.ssh/deploy_shop');
        // A crontab with the old script's line (fake `crontab`).
        $this->env['CPD_CRONTAB'] = $this->tmp . '/crontab.txt';
        file_put_contents($this->tmp . '/crontab.txt', "*/5 * * * * /usr/bin/true # other\n*/2 * * * * ~/deployments/shop/deploy.sh # git-deploy:shop\n");
        $this->fakeBin('crontab', "#!/bin/sh\nif [ \"\$1\" = \"-l\" ]; then cat \"\$CPD_CRONTAB\"; else cat > \"\$CPD_CRONTAB\"; fi\n");

        $screen = $this->wizard([
            ['Import a site set up with cpanel-git-setup.sh?', 'shop'],
            ['Site name', null],
            ['Project type', 'laravel'],
            ['Deploy to which domain?', 'd:1'],
            ['Continue?', 'continue'],
            ['Which PHP should the site use?', 'ea-php83'],
            ["Also set this as the domain's PHP version", true],
            ['Which Node.js builds the frontend?', 'i:20.19.5'],
            ['Where does the .env come from?', 'example'],
            ['APP_NAME', 'Shop'],
            ['APP_URL', null],
            ['Database', 'sqlite'],
            ['Select a step to change when it runs', 'done'],
            ['Create the site?', 'create_deploy'],
            ['Migrations', 'yes'],
            ['Deploy', 'deploy'],
            ['Remove this cron line?', true],
            ['Remove the old webhook file', true],
        ]);

        self::assertStringContainsString('The old setup ran (BUILD_CMD): npm ci && npm run build', $screen);
        self::assertStringContainsString('The old setup ran (POST_CMD): php artisan migrate --force', $screen);
        self::assertStringContainsString('Copied the deploy key of the old setup to ~/.ssh/cpdeploy_shop', $screen);
        self::assertStringContainsString('Access OK', $screen);
        self::assertSame($oldKey, file_get_contents($this->home . '/.ssh/cpdeploy_shop'), 'the old key is copied');
        self::assertFileExists($this->home . '/.ssh/deploy_shop', 'and left in place');

        $site = $this->site();
        self::assertSame(['acme', 'shop', 'main'], [$site['repo']['owner'], $site['repo']['name'], $site['repo']['branch']]);
        self::assertSame('8.3', $site['php']['version']);
        self::assertSame(self::DOMAIN, $site['domain']['name']);

        self::assertNotNull($this->current(), 'the first deploy ran');
        self::assertSame("*/5 * * * * /usr/bin/true # other\n", file_get_contents($this->tmp . '/crontab.txt'));
        self::assertFileDoesNotExist($hooks . '/deploy-hook-shop-a1b2c3.php');
        self::assertDirectoryExists($legacy, '~/deployments/shop is never deleted');
        self::assertStringContainsString('Also delete the webhook on GitHub', $screen);
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function pickFromList(): array
    {
        return [
            ['How do you want to pick the repository?', 'list'],
            ['Which repository?', 'acme/shop'],
            ['Which branch?', 'main'],
            ['Site name', 'shop'],
        ];
    }

    /**
     * Steps 3–7 with the usual answers.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private function middleSteps(): array
    {
        return [
            ['Project type', 'laravel'],
            ['Deploy to which domain?', 'd:1'],
            ['Continue?', 'continue'],
            ['Which PHP should the site use?', 'ea-php82'],
            ["Also set this as the domain's PHP version", true],
            ['Which Node.js builds the frontend?', 'i:20.19.5'],
        ];
    }

    private function saveToken(): void
    {
        mkdir($this->root . '/secrets', 0700, true);
        file_put_contents($this->root . '/secrets/github-token', self::TOKEN . "\n");
        chmod($this->root . '/secrets/github-token', 0600);
    }

    /**
     * Runs the wizard with $answers; every answer must be used.
     *
     * @param list<mixed> $answers
     */
    private function wizard(array $answers): string
    {
        $asker = new ScriptedAsker($answers);
        $output = new BufferedOutput();
        $services = $this->services();
        $theme = new Theme(true);
        $ctx = new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme);
        try {
            (new AddSiteWizard($ctx))->run();
        } catch (LogicException $e) {
            self::fail($e->getMessage() . "\n\nScreen so far:\n" . $output->fetch() . "\nAsked:\n" . implode("\n", array_map(static fn ($q) => $q['type'] . ': ' . $q['label'], $asker->asked)));
        }
        $text = $output->fetch();
        self::assertSame(0, $asker->remaining(), "Unused answers. Screen:\n" . $text);

        return $text;
    }
}
