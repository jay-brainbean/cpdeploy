<?php

declare(strict_types=1);

namespace Cpdeploy\Config\Schema;

/**
 * Defaults, validation and schema migration for ~/cpdeploy/config.yml (§8.1, CFG-02/03).
 */
final class GlobalSchema
{
    public const VERSION = 1;

    public const DEFAULT_UPDATE_REPO = 'jay-brainbean/cpdeploy';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema' => self::VERSION,
            'defaults' => [
                'keep_releases' => 5,
                'sync_multiphp' => true,
                'health_check' => true,
            ],
            'timeouts' => [
                'git' => 300,
                'composer' => 900,
                'node_install' => 1200,
                'node_build' => 1200,
                'artisan' => 300,
                'migrate' => 1800,
                'custom' => 600,
                'http' => 30,
            ],
            'mirrors' => [
                'node' => 'https://nodejs.org/dist',
                'composer' => 'https://getcomposer.org/download',
            ],
            'ui' => [
                'unicode' => 'auto',
                'color' => 'auto',
                'editor' => '',
            ],
            'update' => [
                'repo' => self::DEFAULT_UPDATE_REPO,
            ],
        ];
    }

    /**
     * Fills in missing keys from the defaults, recursively. Unknown keys are kept.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function withDefaults(array $data): array
    {
        return self::merge(self::defaults(), $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::unknownKeys(self::defaults(), $data) as $key) {
            $warnings[] = "Unknown setting '{$key}' (a typo?) — kept but ignored";
        }

        $keep = $data['defaults']['keep_releases'] ?? null;
        if (!is_int($keep) || $keep < 1 || $keep > 50) {
            $errors[] = 'defaults.keep_releases: must be a whole number from 1 to 50';
        }
        foreach (['sync_multiphp', 'health_check'] as $key) {
            if (!is_bool($data['defaults'][$key] ?? null)) {
                $errors[] = "defaults.{$key}: must be true or false";
            }
        }
        foreach (array_keys(self::defaults()['timeouts']) as $key) {
            $value = $data['timeouts'][$key] ?? null;
            if (!is_int($value) || $value < 5 || $value > 86400) {
                $errors[] = "timeouts.{$key}: must be a number of seconds from 5 to 86400";
            }
        }
        foreach (['node', 'composer'] as $key) {
            $value = $data['mirrors'][$key] ?? null;
            if (!is_string($value) || preg_match('#^https?://[^\s]+$#', $value) !== 1) {
                $errors[] = "mirrors.{$key}: must be an http(s) URL";
            }
        }
        foreach (['unicode', 'color'] as $key) {
            $value = $data['ui'][$key] ?? null;
            if (!is_bool($value) && $value !== 'auto') {
                $errors[] = "ui.{$key}: must be auto, true or false";
            }
        }
        if (!is_string($data['ui']['editor'] ?? null)) {
            $errors[] = 'ui.editor: must be text ("" = $VISUAL → $EDITOR → nano → vi)';
        }
        $repo = $data['update']['repo'] ?? null;
        if (!is_string($repo) || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1) {
            $errors[] = 'update.repo: must be OWNER/REPO';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public static function needsMigration(int $schema): bool
    {
        return $schema < self::VERSION;
    }

    /**
     * Migrates an older schema forward (CFG-02). Schema 1 is the first; nothing to do yet.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data, int $from): array
    {
        $data['schema'] = self::VERSION;

        return $data;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function unknownKeys(array $defaults, array $data, string $prefix = ''): array
    {
        $unknown = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (!array_key_exists($key, $defaults)) {
                $unknown[] = $path;
            } elseif (is_array($defaults[$key]) && is_array($value)) {
                /** @var array<string, mixed> $sub */
                $sub = $defaults[$key];
                /** @var array<string, mixed> $value */
                array_push($unknown, ...self::unknownKeys($sub, $value, $path . '.'));
            }
        }

        return $unknown;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                /** @var array<string, mixed> $b */
                $b = $base[$key];
                /** @var array<string, mixed> $value */
                $base[$key] = self::merge($b, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
