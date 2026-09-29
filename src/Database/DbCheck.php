<?php

declare(strict_types=1);

namespace Cpdeploy\Database;

use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * DB-04: connection check with the **site** PHP and a small inline PDO script.
 * Credentials travel in CPD_DB_* environment variables, never in argv (SEC-03).
 */
final class DbCheck
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const NO_DRIVER = 'driver';
    public const SKIPPED = 'skipped';

    private const SCRIPT = <<<'PHP'
        $d = getenv('CPD_DB_DRIVER');
        $map = ['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'];
        if (!isset($map[$d])) { echo 'UNSUPPORTED ', $d; exit(0); }
        if (!extension_loaded($map[$d])) { echo 'DRIVER ', $map[$d]; exit(0); }
        try {
            if ($d === 'sqlite') {
                $f = (string) getenv('CPD_DB_NAME');
                if ($f !== ':memory:' && !is_file($f)) { echo 'ERROR database file does not exist: ', $f; exit(0); }
                $dsn = 'sqlite:' . $f;
            } else {
                $dsn = ($d === 'pgsql' ? 'pgsql' : 'mysql') . ':host=' . getenv('CPD_DB_HOST') . ';port=' . getenv('CPD_DB_PORT') . ';dbname=' . getenv('CPD_DB_NAME');
            }
            $user = getenv('CPD_DB_USER');
            $pass = getenv('CPD_DB_PASS');
            new PDO($dsn, $user === false || $user === '' ? null : $user, $pass === false ? null : $pass, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo 'OK';
        } catch (Throwable $e) {
            echo 'ERROR ', $e->getMessage();
        }
        PHP;

    public function __construct(private readonly Shell $shell)
    {
    }

    /**
     * @param array<string, string> $env    the site's .env values
     * @param string                $appDir folder a relative/missing sqlite path resolves against
     * @param string                $defaultDriver DB_CONNECTION when .env doesn't set it
     */
    public function check(string $phpBinary, array $env, string $appDir, string $defaultDriver = 'mysql'): DbCheckResult
    {
        $driver = strtolower($env['DB_CONNECTION'] ?? $defaultDriver);
        if ($driver === '') {
            $driver = $defaultDriver;
        }
        $name = $env['DB_DATABASE'] ?? '';
        if ($driver === 'sqlite') {
            if ($name === '') {
                $name = rtrim($appDir, '/') . '/database/database.sqlite';
            } elseif ($name !== ':memory:' && !str_starts_with($name, '/')) {
                $name = rtrim($appDir, '/') . '/' . $name;
            }
        }
        $port = $env['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');

        $result = $this->shell->run([$phpBinary, '-r', self::SCRIPT], new RunOptions(
            cwd: is_dir($appDir) ? $appDir : null,
            env: [
                'CPD_DB_DRIVER' => $driver,
                'CPD_DB_HOST' => $env['DB_HOST'] ?? '127.0.0.1',
                'CPD_DB_PORT' => $port,
                'CPD_DB_NAME' => $name,
                'CPD_DB_USER' => $env['DB_USERNAME'] ?? '',
                'CPD_DB_PASS' => $env['DB_PASSWORD'] ?? '',
            ],
            timeout: 30,
            label: 'database check',
        ));
        $out = trim($result->stdout);

        return match (true) {
            $out === 'OK' => new DbCheckResult(self::OK, $driver, 'connected'),
            str_starts_with($out, 'DRIVER ') => new DbCheckResult(self::NO_DRIVER, $driver, substr($out, 7)),
            str_starts_with($out, 'UNSUPPORTED') => new DbCheckResult(self::SKIPPED, $driver, "the {$driver} driver isn't checked by cpdeploy"),
            str_starts_with($out, 'ERROR ') => new DbCheckResult(self::FAILED, $driver, substr($out, 6)),
            default => new DbCheckResult(self::FAILED, $driver, $result->successful() ? 'no answer from the check' : implode(' ', $result->lastLines(3))),
        };
    }
}
