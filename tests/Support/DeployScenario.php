<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Harness for deploy scenarios (§16.2, §16.4): a fixture Laravel app in a local
 * "GitHub" remote, fake uapi / PHP 8.2 + 8.3 / Composer mirror / Node + npm, a
 * web server serving the docroot, and a hand-written site.yml (the wizard
 * arrives in M6). Tests run the real CLI in a subprocess.
 */
abstract class DeployScenario extends TestCase
{
    protected const SITE = 'shop';
    protected const DOMAIN = 'shop.example.test';

    protected GitFixture $repo;
    protected LocalServer $mirror;
    protected ?LocalServer $web = null;
    protected string $docroot;
    /** ~/cpdeploy/sites/<site>: site.yml, the mirror, logs, history, backups. */
    protected string $siteDir;
    /** ~/cpdeploy_sites/<domain>: current, releases, shared (LAY-04). */
    protected string $siteFiles;
    protected string $phpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docroot = $this->home . '/' . self::DOMAIN;
        $this->siteDir = $this->root . '/sites/' . self::SITE;
        $this->siteFiles = $this->home . '/cpdeploy_sites/' . self::DOMAIN;
        $this->phpRoot = $this->tmp . '/phps';

        // cPanel
        $this->fakeUapi();
        $this->domainsFixture();
        $this->vhostFixture('ea-php' . str_replace('.', '', $this->phpVersion()));
        $this->uapiFixture('LangPHP', 'php_get_installed_versions', (string) json_encode(['result' => ['status' => 1, 'data' => ['versions' => ['ea-php82', 'ea-php83', 'ea-php84', 'ea-php85']]]]));
        $this->uapiFixture('LangPHP', 'php_get_system_default_version', (string) json_encode(['result' => ['status' => 1, 'data' => ['version' => 'ea-php82']]]));
        $this->env['CPD_WATCH_LINK'] = $this->siteFiles . '/current';
        mkdir($this->root, 0711, true);

        $this->prepareTools();

        // The app on "GitHub"
        $this->repo = new GitFixture($this->tmp . '/work');
        $this->repo->copyFrom($this->appSource());
        $this->repo->commit('Initial app');
        $this->repo->push();
        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = 'file://' . $this->repo->remote;

        // The site, and a web server serving its docroot
        $this->writeSite();
        $this->writeEnv();
        $this->startWeb();
    }

    /**
     * The app pushed to the fake GitHub remote.
     */
    protected function appSource(): string
    {
        return dirname(__DIR__) . '/Fixtures/apps/laravel';
    }

    /**
     * php.version of the hand-written site.yml.
     */
    protected function phpVersion(): string
    {
        return '8.2';
    }

    /**
     * Fake PHP 8.2 + 8.3, a Composer mirror with the fake composer.phar, and Node 20
     * with a fake npm.
     */
    protected function prepareTools(): void
    {
        $this->fakePhp($this->phpRoot, '8.2.31');
        $this->fakePhp($this->phpRoot, '8.3.20');
        $this->env['CPDEPLOY_PHP_SEARCH_PATHS'] = $this->phpRoot;

        $this->startMirror((string) file_get_contents(dirname(__DIR__) . '/Fixtures/composer/fake-composer.php'));

        $this->fakeNode('20.19.5');
        $this->env['CPDEPLOY_NODE_SEARCH_PATHS'] = $this->tmp . '/nodes/*/bin';
        $this->env['CPDEPLOY_ARCH'] = 'x86_64';
        $this->env['CPDEPLOY_GLIBC'] = '2.34';
    }

    /**
     * A local Composer / Node mirror serving $phar as latest-2.x, set in config.yml.
     */
    protected function startMirror(string $phar): void
    {
        $www = $this->tmp . '/mirror';
        mkdir($www . '/latest-2.x', 0777, true);
        $this->publishComposer($phar);
        $this->mirror = new LocalServer($www);
        file_put_contents($this->root . '/config.yml', Yaml::dump([
            'schema' => 1,
            'mirrors' => ['composer' => $this->mirror->url(), 'node' => $this->mirror->url() . '/node'],
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->mirror)) {
            $this->mirror->stop();
        }
        $this->web?->stop();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $over merged into the site.yml values
     */
    protected function writeSite(array $over = []): void
    {
        $data = array_replace_recursive([
            'schema' => 1,
            'name' => self::SITE,
            'type' => 'laravel',
            'site_dir' => 'cpdeploy_sites/' . self::DOMAIN,
            'repo' => ['owner' => 'acme', 'name' => 'shop', 'branch' => 'main', 'transport' => 'ssh22'],
            'domain' => ['name' => self::DOMAIN, 'docroot' => $this->docroot, 'web_dir' => 'public'],
            'php' => ['version' => $this->phpVersion(), 'family' => 'ea', 'sync_multiphp' => true],
            'health_check' => ['attempts' => 1, 'timeout' => 5],
        ], $over);
        if (!is_dir($this->siteDir)) {
            mkdir($this->siteDir, 0711, true);
        }
        file_put_contents($this->siteDir . '/site.yml', Yaml::dump($data, 4, 2));
        chmod($this->siteDir . '/site.yml', 0600);
    }

    /**
     * @return array<string, mixed>
     */
    protected function site(): array
    {
        $data = Yaml::parseFile($this->siteDir . '/site.yml');

        return is_array($data) ? $data : [];
    }

    protected function writeEnv(?string $content = null): void
    {
        $shared = $this->siteFiles . '/shared';
        if (!is_dir($shared . '/database')) {
            mkdir($shared . '/database', 0755, true);
        }
        touch($shared . '/database/database.sqlite');
        file_put_contents($shared . '/.env', $content ?? implode("\n", [
            'APP_NAME=Shop',
            'APP_ENV=production',
            'APP_KEY=base64:' . base64_encode(str_repeat('k', 32)),
            'APP_DEBUG=false',
            'APP_URL=https://' . self::DOMAIN,
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=' . $shared . '/database/database.sqlite',
            'DB_PASSWORD=supersecretpassword',
            '',
        ]));
        chmod($shared . '/.env', 0600);
    }

    /**
     * DomainInfo::domains_data: public_html (main) and the site's addon domain.
     *
     * @param list<array{0: string, 1: string}> $extra [domain, docroot] addon domains
     */
    protected function domainsFixture(array $extra = [], ?string $siteDocroot = null): void
    {
        $addons = [['domain' => self::DOMAIN, 'documentroot' => $siteDocroot ?? $this->docroot, 'ip' => '127.0.0.1', 'type' => 'addon_domain']];
        foreach ($extra as [$name, $root]) {
            $addons[] = ['domain' => $name, 'documentroot' => $root, 'ip' => '127.0.0.1', 'type' => 'addon_domain'];
        }
        $this->uapiFixture('DomainInfo', 'domains_data', (string) json_encode(['result' => ['status' => 1, 'errors' => null, 'data' => [
            'main_domain' => ['domain' => 'main.example.test', 'documentroot' => $this->home . '/public_html', 'ip' => '127.0.0.1', 'type' => 'main_domain'],
            'addon_domains' => $addons,
            'sub_domains' => [],
            'parked_domains' => [],
        ]]]));
    }

    protected function vhostFixture(string $version): void
    {
        $this->uapiFixture('LangPHP', 'php_get_vhost_versions', (string) json_encode(['result' => ['status' => 1, 'errors' => null, 'data' => [
            ['vhost' => self::DOMAIN, 'version' => $version, 'documentroot' => $this->docroot, 'php_fpm' => 1, 'main_domain' => 0, 'phpversion_source' => ['domain' => self::DOMAIN]],
        ]]]));
    }

    protected function publishComposer(string $phar, ?string $sha = null): void
    {
        $dir = $this->tmp . '/mirror/latest-2.x';
        file_put_contents($dir . '/composer.phar', $phar);
        file_put_contents($dir . '/composer.phar.sha256', ($sha ?? hash('sha256', $phar)) . "  composer.phar\n");
    }

    /**
     * A fake Node bin folder: `node -v` prints the version; `npm` simulates
     * ci/install and `run build` (CPD_FAKE_NPM=fail|oom makes the build fail).
     */
    protected function fakeNode(string $version, ?string $dir = null): string
    {
        $bin = $dir ?? $this->tmp . '/nodes/v' . $version . '/bin';
        if (!is_dir($bin)) {
            mkdir($bin, 0777, true);
        }
        file_put_contents($bin . '/node', "#!/bin/sh\n[ \"\$1\" = \"-v\" ] && { echo v{$version}; exit 0; }\nexit 0\n");
        file_put_contents($bin . '/npm', (string) file_get_contents(dirname(__DIR__) . '/Fixtures/node/fake-npm.sh'));
        chmod($bin . '/node', 0755);
        chmod($bin . '/npm', 0755);

        return $bin;
    }

    /**
     * A web server whose document root is re-resolved on every request (like
     * Apache following the docroot symlink). $root: serve a fixed folder instead.
     */
    protected function startWeb(?string $root = null): void
    {
        $this->web?->stop();
        $this->web = DocrootWeb::start($this->tmp, $root ?? $this->docroot);
        $this->env['CPDEPLOY_HTTP_OVERRIDE'] = '127.0.0.1:' . $this->web->port;
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function deploy(array $args = ['--yes']): array
    {
        return $this->runCli(['deploy', self::SITE, ...$args], null, 120);
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $r
     */
    protected function assertExit(int $expected, array $r): void
    {
        self::assertSame($expected, $r['exit'], "stdout:\n{$r['stdout']}\nstderr:\n{$r['stderr']}\nlog:\n" . $this->lastLog());
    }

    protected function current(): ?string
    {
        $link = $this->siteFiles . '/current';

        return is_link($link) ? (string) readlink($link) : null;
    }

    protected function liveDir(): string
    {
        return $this->siteFiles . '/' . $this->current();
    }

    /**
     * @return array<string, array<string, mixed>> id => .release.json
     */
    protected function releases(): array
    {
        $out = [];
        foreach (glob($this->siteFiles . '/releases/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $data = json_decode((string) @file_get_contents($dir . '/.release.json'), true);
            $out[basename($dir)] = is_array($data) ? $data : [];
        }
        ksort($out);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function history(): array
    {
        $file = $this->siteDir . '/history.jsonl';
        $out = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $out[] = (array) json_decode($line, true);
        }

        return $out;
    }

    /**
     * @return list<array{cmd: string, args: list<string>, php: string, release: string}>
     */
    protected function artisanCalls(): array
    {
        $file = $this->siteFiles . '/shared/storage/logs/fake-artisan.log';
        $out = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            /** @var array{cmd: string, args: list<string>, php: string, release: string} $call */
            $call = json_decode($line, true);
            $out[] = $call;
        }

        return $out;
    }

    protected function lastLog(): string
    {
        $logs = glob($this->siteDir . '/logs/*.log') ?: [];
        sort($logs);

        return $logs === [] ? '(no log)' : (string) file_get_contents((string) end($logs));
    }

    /**
     * The invariants every scenario checks (§16.4): no state file left, docroot and
     * current are relative links when present, shared survives.
     */
    protected function assertInvariants(): void
    {
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json', 'state file left behind');
        if (is_link($this->siteFiles . '/current')) {
            self::assertStringStartsWith('releases/', (string) readlink($this->siteFiles . '/current'));
        }
        if (is_link($this->docroot)) {
            self::assertStringStartsNotWith('/', (string) readlink($this->docroot), 'LAY-01: relative docroot link');
        }
        self::assertFileExists($this->siteFiles . '/shared/.env');
    }

    /**
     * Adds a commit to the app and pushes it.
     *
     * @param array<string, string> $files path => content
     */
    protected function change(array $files, string $message): string
    {
        foreach ($files as $path => $content) {
            $this->repo->write($path, $content);
        }
        $sha = $this->repo->commit($message);
        $this->repo->push();

        return $sha;
    }
}
