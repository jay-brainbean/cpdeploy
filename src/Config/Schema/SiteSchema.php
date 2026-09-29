<?php

declare(strict_types=1);

namespace Cpdeploy\Config\Schema;

use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Support\Errors\CpdeployException;

/**
 * Defaults, validation (VAL-01…12, SEC-11) and schema migration for
 * ~/cpdeploy/sites/<site>/site.yml (§8.2, §8.3, CFG-02/03).
 */
final class SiteSchema
{
    public const VERSION = 1;

    public const TYPES = ['laravel', 'static', 'php', 'custom'];

    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,30}$/';

    /** VAL-01: command names can't be site names. */
    public const RESERVED = [
        'menu', 'add', 'deploy', 'rollback', 'releases', 'status', 'check', 'php', 'node', 'env',
        'artisan', 'down', 'up', 'logs', 'config', 'key', 'token', 'recover', 'remove', 'self-update',
        'settings', 'help', 'list',
    ];

    /** VAL-07: allowed "when" values per built-in step. */
    public const STEP_VALUES = [
        'composer_install' => ['every', 'ask', 'off'],
        'frontend_build' => ['every', 'ask', 'off'],
        'storage_link' => ['every', 'off'],
        'optimize' => ['every', 'off'],
        'migrate' => ['every', 'ask', 'off'],
        'maintenance' => ['with_migrations', 'off'],
        'seed' => ['first', 'ask', 'off'],
        'queue_restart' => ['every', 'off'],
    ];

    /** VAL-08 / REL-02: never shared. */
    public const NEVER_SHARED = ['storage/framework/views', 'bootstrap/cache', 'vendor', 'node_modules'];

    /**
     * §8.2 with the Laravel values. Presets (§8.4) override parts of it per type.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema' => self::VERSION,
            'name' => '',
            'type' => 'laravel',
            'strategy' => 'releases',
            'created_at' => null,
            'repo' => [
                'owner' => '',
                'name' => '',
                'branch' => 'main',
                'transport' => 'ssh22',
                'deploy_key_id' => null,
            ],
            'domain' => [
                'name' => '',
                'docroot' => '',
                'web_dir' => 'public',
                'ip' => null,
                'converted_at' => null,
                'backup' => null,
            ],
            'php' => [
                'version' => '',
                'family' => 'ea',
                'sync_multiphp' => true,
            ],
            'node' => [
                'version' => 'auto',
                'package_manager' => 'auto',
                'build_script' => 'build',
                'expect_files' => [],
                'build_outputs' => ['public/build'],
                'remove_node_modules' => true,
                'max_old_space_mb' => null,
            ],
            'composer' => [
                'version' => '2',
                'install_flags' => '--no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress',
                'allow_no_lock' => false,
            ],
            'steps' => [
                'composer_install' => 'ask',
                'frontend_build' => 'every',
                'storage_link' => 'every',
                'optimize' => 'every',
                'migrate' => 'ask',
                'maintenance' => 'with_migrations',
                'seed' => 'off',
                'queue_restart' => 'off',
            ],
            'custom_commands' => [],
            'shared' => [
                'files' => ['.env'],
                'dirs' => ['storage/app', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions'],
                'docroot_files' => ['.user.ini', 'php.ini'],
                'docroot_dirs' => ['.well-known'],
            ],
            'releases' => [
                'keep' => 5,
            ],
            'maintenance' => [
                'options' => '--retry=60',
                'secret' => null,
            ],
            'health_check' => [
                'enabled' => true,
                'path' => '/',
                'expect' => '200-399',
                'timeout' => 20,
                'attempts' => 3,
                'on_failure' => 'ask',
            ],
            'database' => [
                'created_by_cpdeploy' => false,
                'name' => null,
                'user' => null,
            ],
        ];
    }

    /**
     * defaults ← preset of the file's type ← the file. Unknown keys are kept (CFG-03).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $preset
     * @return array<string, mixed>
     */
    public static function withDefaults(array $data, array $preset = []): array
    {
        return self::merge(self::merge(self::defaults(), $preset), $data);
    }

    /**
     * Every problem, with its key path and the allowed values (CFG-03). Checks that
     * need the server (DOC-01 a–c, e; composer_install: off vs composer.json) are
     * done by SiteRegistry and the deploy preflight.
     *
     * @param array<string, mixed> $data merged with the defaults
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $warnings = [];
        foreach (self::unknownKeys(self::defaults(), $data) as $key) {
            $warnings[] = "Unknown setting '{$key}' (a typo?) — kept but ignored";
        }

        // VAL-01
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = 'name: must be 1–31 characters of a–z, 0–9 and "-", starting with a letter or digit';
        } elseif (in_array($name, self::RESERVED, true)) {
            $errors[] = "name: '{$name}' is reserved (it's a cpdeploy command)";
        }

        // VAL-02
        if (!in_array($data['type'] ?? null, self::TYPES, true)) {
            $errors[] = 'type: must be one of ' . implode(', ', self::TYPES);
        }
        if (($data['strategy'] ?? null) !== 'releases') {
            $errors[] = 'strategy: must be releases (the only strategy in this version)';
        }

        $repo = self::section($data, 'repo');
        if (!is_string($repo['owner'] ?? null) || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $repo['owner']) !== 1) {
            $errors[] = 'repo.owner: must be a GitHub user or organisation name';
        }
        if (!is_string($repo['name'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repo['name']) !== 1) {
            $errors[] = 'repo.name: must be a GitHub repository name';
        }
        if (!self::isRefName($repo['branch'] ?? null)) {
            $errors[] = 'repo.branch: must be a branch name';
        }
        if (!in_array($repo['transport'] ?? null, ['ssh22', 'ssh443'], true)) {
            $errors[] = 'repo.transport: must be ssh22 or ssh443';
        }
        $keyId = $repo['deploy_key_id'] ?? null;
        if ($keyId !== null && (!is_int($keyId) || $keyId < 1)) {
            $errors[] = 'repo.deploy_key_id: must be a GitHub key id or null';
        }

        // VAL-03 / VAL-04
        $domain = self::section($data, 'domain');
        if (!is_string($domain['name'] ?? null) || preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/', $domain['name']) !== 1) {
            $errors[] = 'domain.name: must be a domain name such as shop.example.com';
        }
        $docroot = $domain['docroot'] ?? null;
        if (!is_string($docroot) || !str_starts_with($docroot, '/') || str_contains($docroot, '/../') || str_ends_with($docroot, '/..')) {
            $errors[] = 'domain.docroot: must be an absolute path (from cPanel → Domains)';
        }
        $webDir = $domain['web_dir'] ?? null;
        if (!is_string($webDir) || !self::isRelativePath($webDir, true)) {
            $errors[] = 'domain.web_dir: must be a relative folder without ".." ("" = the release root)';
            $webDir = null;
        }
        foreach (['ip', 'converted_at', 'backup'] as $key) {
            $value = $domain[$key] ?? null;
            if ($value !== null && !is_string($value)) {
                $errors[] = "domain.{$key}: must be text or null";
            }
        }

        // VAL-05
        $php = self::section($data, 'php');
        if (!is_string($php['version'] ?? null) || preg_match('/^\d\.\d$/', $php['version']) !== 1) {
            $errors[] = 'php.version: must be major.minor, e.g. "8.2" (quote it in YAML)';
        }
        if (!in_array($php['family'] ?? null, ['ea', 'alt'], true)) {
            $errors[] = 'php.family: must be ea or alt';
        }
        if (!is_bool($php['sync_multiphp'] ?? null)) {
            $errors[] = 'php.sync_multiphp: must be true or false';
        }

        // VAL-06
        $node = self::section($data, 'node');
        $nodeVersion = $node['version'] ?? null;
        if (!is_string($nodeVersion) || $nodeVersion === '') {
            $errors[] = 'node.version: must be auto, none, or a version such as "22" or lts/*';
        } elseif (!in_array(strtolower($nodeVersion), ['auto', 'none'], true)) {
            try {
                NodeSpec::parse($nodeVersion, 'site.yml (node.version)');
            } catch (CpdeployException) {
                $errors[] = "node.version: '{$nodeVersion}' isn't a Node version (use auto, none, 22, 22.20.0, ^20, lts/*)";
            }
        }
        if (!in_array($node['package_manager'] ?? null, ['auto', 'npm', 'pnpm', 'yarn'], true)) {
            $errors[] = 'node.package_manager: must be auto, npm, pnpm or yarn';
        }
        if (!is_string($node['build_script'] ?? null) || preg_match('/^[A-Za-z0-9:._-]*$/', $node['build_script']) !== 1) {
            $errors[] = 'node.build_script: must be a package.json script name ("" = no frontend build)';
        }
        foreach (['expect_files', 'build_outputs'] as $key) {
            if (!self::isPathList($node[$key] ?? null)) {
                $errors[] = "node.{$key}: must be a list of relative paths without \"..\"";
            }
        }
        if (!is_bool($node['remove_node_modules'] ?? null)) {
            $errors[] = 'node.remove_node_modules: must be true or false';
        }
        $heap = $node['max_old_space_mb'] ?? null;
        if ($heap !== null && (!is_int($heap) || $heap < 128 || $heap > 65536)) {
            $errors[] = 'node.max_old_space_mb: must be null or a number of MB from 128 to 65536';
        }

        $composer = self::section($data, 'composer');
        $cv = $composer['version'] ?? null;
        if (!is_string($cv) || preg_match('/^(2|stable|2\.2|lts|\d+\.\d+\.\d+)$/', $cv) !== 1) {
            $errors[] = 'composer.version: must be 2, stable, 2.2 (or lts), or an exact version such as 2.8.4';
        }
        if (!is_string($composer['install_flags'] ?? null)) {
            $errors[] = 'composer.install_flags: must be text';
        }
        if (!is_bool($composer['allow_no_lock'] ?? null)) {
            $errors[] = 'composer.allow_no_lock: must be true or false';
        }

        // VAL-07
        $steps = self::section($data, 'steps');
        foreach (self::STEP_VALUES as $step => $allowed) {
            if (!in_array($steps[$step] ?? null, $allowed, true)) {
                $errors[] = "steps.{$step}: must be " . implode(', ', $allowed);
            }
        }

        // VAL-11
        $commands = $data['custom_commands'] ?? [];
        if (!is_array($commands) || !array_is_list($commands)) {
            $errors[] = 'custom_commands: must be a list';
        } else {
            foreach ($commands as $i => $command) {
                $prefix = "custom_commands[{$i}]";
                if (!is_array($command)) {
                    $errors[] = "{$prefix}: must be a mapping with name, run, phase, when, timeout, on_error";
                    continue;
                }
                $cname = $command['name'] ?? null;
                if (!is_string($cname) || trim($cname) === '' || mb_strlen($cname) > 40) {
                    $errors[] = "{$prefix}.name: must be 1–40 characters";
                }
                if (!is_string($command['run'] ?? null) || trim($command['run']) === '') {
                    $errors[] = "{$prefix}.run: must not be empty";
                }
                if (!in_array($command['phase'] ?? 'before_activate', ['before_activate', 'after_activate'], true)) {
                    $errors[] = "{$prefix}.phase: must be before_activate or after_activate";
                }
                if (!in_array($command['when'] ?? 'every', ['every', 'ask', 'first', 'off'], true)) {
                    $errors[] = "{$prefix}.when: must be every, ask, first or off";
                }
                $timeout = $command['timeout'] ?? null;
                if ($timeout !== null && (!is_int($timeout) || $timeout < 1 || $timeout > 7200)) {
                    $errors[] = "{$prefix}.timeout: must be 1–7200 seconds";
                }
                if (!in_array($command['on_error'] ?? 'fail', ['fail', 'warn'], true)) {
                    $errors[] = "{$prefix}.on_error: must be fail or warn";
                }
            }
        }

        // VAL-08
        $shared = self::section($data, 'shared');
        foreach (['files', 'dirs', 'docroot_files', 'docroot_dirs'] as $key) {
            if (!self::isPathList($shared[$key] ?? null)) {
                $errors[] = "shared.{$key}: must be a list of relative paths without \"..\"";
            }
        }
        if (self::isPathList($shared['files'] ?? null) && self::isPathList($shared['dirs'] ?? null)) {
            /** @var list<string> $files */
            $files = $shared['files'];
            /** @var list<string> $dirs */
            $dirs = $shared['dirs'];
            $all = array_map(static fn (string $p): string => trim($p, '/'), [...$files, ...$dirs]);
            foreach ($all as $i => $path) {
                foreach (self::NEVER_SHARED as $never) {
                    if ($path === $never || str_starts_with($path, $never . '/') || str_starts_with($never, $path . '/')) {
                        $errors[] = "shared: '{$path}' can't be shared ({$never} must stay per release)";
                    }
                }
                if ($webDir !== null && $webDir !== '' && ($path === trim($webDir, '/') || str_starts_with($path, trim($webDir, '/') . '/'))) {
                    $errors[] = "shared: '{$path}' is inside the web dir; use shared.docroot_files / docroot_dirs instead";
                }
                foreach ($all as $j => $other) {
                    if ($i < $j && ($path === $other || str_starts_with($other, $path . '/') || str_starts_with($path, $other . '/'))) {
                        $errors[] = "shared: '{$path}' and '{$other}' overlap";
                    }
                }
            }
            // SEC-11: serving the release root would expose shared files at the root.
            if ($webDir === '') {
                foreach ($files as $file) {
                    if (!str_contains(trim($file, '/'), '/')) {
                        $errors[] = 'domain.web_dir: serving the release root would expose ' . $file . '. Put your public files in a folder such as public/.';
                        break;
                    }
                }
            }
        }

        // VAL-09
        $keep = self::section($data, 'releases')['keep'] ?? null;
        if (!is_int($keep) || $keep < 2 || $keep > 30) {
            $errors[] = 'releases.keep: must be a whole number from 2 to 30';
        }

        // VAL-12
        $maintenance = self::section($data, 'maintenance');
        if (!is_string($maintenance['options'] ?? null)) {
            $errors[] = 'maintenance.options: must be text (extra flags for php artisan down)';
        }
        $secret = $maintenance['secret'] ?? null;
        if ($secret !== null && (!is_string($secret) || preg_match('/^[A-Za-z0-9-]{8,64}$/', $secret) !== 1)) {
            $errors[] = 'maintenance.secret: must be null or 8–64 characters of A–Z, a–z, 0–9 and "-"';
        }

        // VAL-10
        $health = self::section($data, 'health_check');
        if (!is_bool($health['enabled'] ?? null)) {
            $errors[] = 'health_check.enabled: must be true or false';
        }
        if (!is_string($health['path'] ?? null) || !str_starts_with($health['path'], '/')) {
            $errors[] = 'health_check.path: must start with /, e.g. / or /up';
        }
        if (!is_string($health['expect'] ?? null) || self::parseExpect($health['expect']) === null) {
            $errors[] = 'health_check.expect: must be a status range such as 200-399, or a list such as 200,301,302 (100–599)';
        }
        $timeout = $health['timeout'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 120) {
            $errors[] = 'health_check.timeout: must be 1–120 seconds';
        }
        $attempts = $health['attempts'] ?? null;
        if (!is_int($attempts) || $attempts < 1 || $attempts > 10) {
            $errors[] = 'health_check.attempts: must be 1–10';
        }
        if (!in_array($health['on_failure'] ?? null, ['ask', 'rollback', 'keep'], true)) {
            $errors[] = 'health_check.on_failure: must be ask, rollback or keep';
        }

        $database = self::section($data, 'database');
        if (!is_bool($database['created_by_cpdeploy'] ?? null)) {
            $errors[] = 'database.created_by_cpdeploy: must be true or false';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }

    /**
     * "200-399" or "200,301,302" (or a mix) → list of [from, to] ranges within 100–599.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    public static function parseExpect(string $expect): ?array
    {
        $ranges = [];
        foreach (explode(',', $expect) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d{3})(?:\s*-\s*(\d{3}))?$/', $part, $m) !== 1) {
                return null;
            }
            $from = (int) $m[1];
            $to = isset($m[2]) ? (int) $m[2] : $from;
            if ($from < 100 || $to > 599 || $from > $to) {
                return null;
            }
            $ranges[] = [$from, $to];
        }

        return $ranges;
    }

    public static function needsMigration(int $schema): bool
    {
        return $schema < self::VERSION;
    }

    /**
     * Migrates an older schema forward (CFG-02). Schema 1 is the first.
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
     * A relative path: no leading "/", no "..", no empty segments. "" only when $allowEmpty.
     */
    public static function isRelativePath(string $path, bool $allowEmpty = false): bool
    {
        if ($path === '') {
            return $allowEmpty;
        }
        if (str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isPathList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || !self::isRelativePath($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isRefName(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match('#^(?!/)(?!.*(?:\.\.|//|@\{|\.lock$))[^\s~^:?*\[\\\\]+(?<!/)$#', $value) === 1;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        /** @var array<string, mixed> $value */
        return $value;
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
            } elseif (is_array($defaults[$key]) && !array_is_list($defaults[$key]) && is_array($value)) {
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
            // An empty mapping ({} parses as []) keeps the defaults of that section.
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])
                && ($value === [] || !array_is_list($value))) {
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
