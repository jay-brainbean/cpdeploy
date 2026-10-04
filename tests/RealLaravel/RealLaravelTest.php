<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\RealLaravel;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * The `real-laravel` CI job (§16.6): a real laravel/laravel app, real Composer,
 * real Node, SQLite; fakes only for uapi and the web server. Deploys twice (the
 * second with Composer skipped), rolls back to the first release (§17 M4), and
 * checks the site answers through the docroot symlink each time.
 *
 * Runs only when CPDEPLOY_REAL_LARAVEL_APP names a `composer create-project
 * laravel/laravel` folder. Optional:
 *   CPDEPLOY_REAL_LARAVEL_COMPOSER_PHAR   serve this composer.phar from a local mirror
 *   CPDEPLOY_REAL_LARAVEL_COMPOSER_FLAGS  composer.install_flags for the site
 */
final class RealLaravelTest extends DeployScenario
{
    protected function setUp(): void
    {
        $app = getenv('CPDEPLOY_REAL_LARAVEL_APP');
        if (!is_string($app) || !is_file($app . '/artisan')) {
            self::markTestSkipped('Set CPDEPLOY_REAL_LARAVEL_APP to a laravel/laravel project folder');
        }
        parent::setUp();

        // Proxies and CA bundles reach Composer, git and npm (SH-03 pass-through).
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE', 'NODE_EXTRA_CA_CERTS', 'GIT_SSL_CAINFO'] as $var) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                $this->env[$var] = $value;
            }
        }

        $flags = getenv('CPDEPLOY_REAL_LARAVEL_COMPOSER_FLAGS');
        $this->writeSite(array_filter([
            'composer' => is_string($flags) && $flags !== '' ? ['install_flags' => $flags] : null,
            'health_check' => ['attempts' => 3, 'timeout' => 30],
        ]));

        // The app's own .env.example, made production-ready, with SQLite in shared/.
        $example = (string) file_get_contents($this->repo->work . '/.env.example');
        $database = $this->siteFiles . '/shared/database/database.sqlite';
        $env = (string) preg_replace(
            ['/^APP_KEY=.*$/m', '/^APP_ENV=.*$/m', '/^APP_DEBUG=.*$/m', '/^APP_URL=.*$/m', '/^DB_CONNECTION=.*$/m', '/^#?\s*DB_DATABASE=.*$/m'],
            ['APP_KEY=base64:' . base64_encode(random_bytes(32)), 'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://' . self::DOMAIN, 'DB_CONNECTION=sqlite', 'DB_DATABASE=' . $database],
            $example,
        );
        if (!str_contains($env, 'DB_DATABASE=')) {
            $env .= "\nDB_DATABASE={$database}\n";
        }
        $this->writeEnv($env);
    }

    protected function appSource(): string
    {
        return (string) getenv('CPDEPLOY_REAL_LARAVEL_APP');
    }

    protected function phpVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * The real PHP in an EasyApache-like layout, the real Composer (downloaded
     * from getcomposer.org unless a local phar is given), and the real Node.
     */
    protected function prepareTools(): void
    {
        $dir = $this->phpRoot . '/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin';
        mkdir($dir, 0777, true);
        symlink(PHP_BINARY, $dir . '/php');
        $this->env['CPDEPLOY_PHP_SEARCH_PATHS'] = $this->phpRoot;

        $phar = getenv('CPDEPLOY_REAL_LARAVEL_COMPOSER_PHAR');
        if (is_string($phar) && is_file($phar)) {
            $this->startMirror((string) file_get_contents($phar));
        }

        $node = trim((string) shell_exec('command -v node'));
        self::assertNotSame('', $node, 'Node.js must be on PATH');
        $this->env['CPDEPLOY_NODE_SEARCH_PATHS'] = dirname((string) realpath($node));
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function deploy(array $args = ['--yes']): array
    {
        return $this->runCli(['deploy', self::SITE, ...$args], null, 1800);
    }

    private function get(string $path = '/'): string
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Host: ' . self::DOMAIN]]);
        $body = @file_get_contents('http://127.0.0.1:' . $this->web?->port . $path, false, $context);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1 ? $m[1] : '000';

        return $status . ' ' . (is_string($body) ? $body : '');
    }

    /**
     * @covers-req CMP-04
     * @covers-req CMP-05
     * @covers-req HC-02
     * @covers-req RB-06
     */
    public function testFirstAndSecondDeployThenRollback(): void
    {
        $first = $this->deploy(['--yes']);
        $this->assertExit(0, $first);
        $this->assertInvariants();
        $a = $this->liveDir();
        self::assertFileExists($a . '/vendor/autoload.php');
        self::assertFileExists($a . '/public/build/manifest.json');
        self::assertFileExists($a . '/bootstrap/cache/config.php');
        self::assertStringStartsWith('200 ', $this->get('/'));

        $routes = (string) file_get_contents($this->repo->work . '/routes/web.php');
        $this->change(['routes/web.php' => $routes . "\n// second deploy\n"], 'Second deploy');

        $second = $this->deploy(['--composer=no', '--yes']);
        $this->assertExit(0, $second);
        $this->assertInvariants();
        $b = $this->liveDir();
        self::assertNotSame($a, $b);
        self::assertFileExists($b . '/vendor/autoload.php');
        self::assertNotSame(fileinode($a . '/vendor/autoload.php'), fileinode($b . '/vendor/autoload.php'));
        self::assertStringContainsString('composer: reuse vendor/ (--composer=no)', $second['stdout']);
        self::assertStringStartsWith('200 ', $this->get('/'));
        self::assertCount(2, $this->history());

        // M4: roll back to the first release; its config cache is rebuilt there.
        unlink($a . '/bootstrap/cache/config.php');
        $rollback = $this->runCli(['rollback', self::SITE, '--previous', '--yes'], null, 600);
        $this->assertExit(0, $rollback);
        $this->assertInvariants();
        self::assertSame($a, $this->liveDir());
        self::assertFileExists($a . '/bootstrap/cache/config.php');
        self::assertStringStartsWith('200 ', $this->get('/'));
        $history = $this->history();
        self::assertSame(['rollback', 'success'], [$history[2]['action'], $history[2]['result']]);
    }
}
