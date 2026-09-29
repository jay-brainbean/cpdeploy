<?php

declare(strict_types=1);

namespace Cpdeploy\Project;

use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * composer.json / composer.lock: package versions, lock diffs (CHG-02) and the
 * platform check against a PHP binary (PHP-05).
 */
final class ComposerInspector
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly PhpService $php,
    ) {
    }

    /**
     * A package's version from a lock ("v12.31.0" → "12.31.0"), or null.
     *
     * @param array<mixed>|null $lock
     */
    public static function packageVersion(?array $lock, string $package): ?string
    {
        return self::lockPackages($lock)[$package] ?? null;
    }

    /**
     * `packages` of a lock: name → version (without a leading "v").
     *
     * @param array<mixed>|null $lock
     * @return array<string, string>
     */
    public static function lockPackages(?array $lock): array
    {
        $out = [];
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $version = is_string($package['version'] ?? null) ? $package['version'] : '';
                $out[$package['name']] = (string) preg_replace('/^v(?=\d)/', '', $version);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * CHG-02 lockDiff.
     *
     * @param array<mixed>|null $old
     * @param array<mixed>|null $new
     * @return array{added: array<string, string>, removed: array<string, string>, updated: array<string, array{0: string, 1: string}>}
     */
    public static function lockDiff(?array $old, ?array $new): array
    {
        $a = self::lockPackages($old);
        $b = self::lockPackages($new);
        $updated = [];
        foreach ($b as $name => $version) {
            if (isset($a[$name]) && $a[$name] !== $version) {
                $updated[$name] = [$a[$name], $version];
            }
        }

        return [
            'added' => array_diff_key($b, $a),
            'removed' => array_diff_key($a, $b),
            'updated' => $updated,
        ];
    }

    /**
     * "4 updated, 1 added" for the composer question (§9.4).
     *
     * @param array{added: array<string, string>, removed: array<string, string>, updated: array<string, array{0: string, 1: string}>} $diff
     */
    public static function summary(array $diff): string
    {
        $parts = [];
        foreach (['updated', 'added', 'removed'] as $key) {
            if ($diff[$key] !== []) {
                $parts[] = count($diff[$key]) . ' ' . $key;
            }
        }

        return $parts === [] ? 'no package changes' : implode(', ', $parts);
    }

    /**
     * PHP-05: what $php lacks for the commit's composer.lock (`composer
     * check-platform-reqs --lock --no-dev --format=json` in a temp copy of
     * composer.json + composer.lock). Without a lock: require.php and ext-* keys.
     *
     * @return list<string>
     */
    public function platformProblems(ProjectInfo $info, CommitFiles $files, PhpInstall $php, string $composerPhar, float $timeout = 120): array
    {
        if (!$info->hasComposer()) {
            return [];
        }
        if (!$info->hasLock()) {
            return $this->php->problemsWithoutLock($info->composerRequire(), $php);
        }
        $dir = $this->fs->tempDir('platform');
        try {
            $this->fs->writeAtomic($dir . '/composer.json', (string) $files->read('composer.json'));
            $this->fs->writeAtomic($dir . '/composer.lock', (string) $files->read('composer.lock'));
            $result = $this->shell->run(
                [$php->binary, $composerPhar, 'check-platform-reqs', '--lock', '--no-dev', '--format=json', '--no-interaction'],
                new RunOptions(cwd: $dir, env: ['COMPOSER_NO_INTERACTION' => '1'], timeout: $timeout, label: 'composer check-platform-reqs'),
            );

            return PhpService::platformProblems($result->stdout !== '' ? $result->stdout : $result->output(), $result->exitCode);
        } finally {
            $this->fs->deleteTree($dir);
        }
    }
}
