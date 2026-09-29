<?php

declare(strict_types=1);

namespace Cpdeploy\Env;

/**
 * ENV-09: a new .env from the commit's .env.example (or a minimal Laravel
 * template), with production settings, the URL, a generated APP_KEY and the
 * database keys.
 */
final class EnvCreator
{
    public const TEMPLATE = <<<'ENV'
        APP_NAME=Laravel
        APP_ENV=production
        APP_KEY=
        APP_DEBUG=false
        APP_URL=http://localhost

        LOG_CHANNEL=stack
        LOG_LEVEL=error

        DB_CONNECTION=mysql
        DB_HOST=127.0.0.1
        DB_PORT=3306
        DB_DATABASE=
        DB_USERNAME=
        DB_PASSWORD=

        SESSION_DRIVER=file
        CACHE_STORE=file
        QUEUE_CONNECTION=sync

        ENV;

    /** DB-03: keys SQLite doesn't use, commented out. */
    public const SQLITE_UNUSED = ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'];

    /**
     * @param array<string, string> $database DB_* values (empty = leave as they are)
     */
    public static function create(?string $example, string $appName, string $appUrl, string $appKey, array $database): string
    {
        $env = EnvFile::parse($example !== null && trim($example) !== '' ? $example : self::TEMPLATE, '.env.example');
        $env = $env
            ->set('APP_NAME', $appName)
            ->set('APP_ENV', 'production')
            ->set('APP_KEY', $appKey)
            ->set('APP_DEBUG', 'false')
            ->set('APP_URL', $appUrl);

        return self::withDatabase($env, $database)->toString();
    }

    /**
     * Sets DB_* on an existing .env text (the wizard's pasted or imported .env).
     *
     * @param array<string, string> $database
     */
    public static function applyDatabase(string $content, array $database): string
    {
        return self::withDatabase(EnvFile::parse($content), $database)->toString();
    }

    /**
     * @param array<string, string> $database
     */
    private static function withDatabase(EnvFile $env, array $database): EnvFile
    {
        foreach ($database as $key => $value) {
            $env = $env->set($key, $value);
        }
        if (($database['DB_CONNECTION'] ?? '') === 'sqlite') {
            foreach (self::SQLITE_UNUSED as $key) {
                $env = $env->comment($key);
            }
        }

        return $env;
    }
}
