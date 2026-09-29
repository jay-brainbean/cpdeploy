<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Git\GitRepository;
use Cpdeploy\Project\ComposerInspector;

/**
 * Diff between the live commit L and the target T (CHG-01, CHG-02).
 * With no L (first deploy) everything counts as new.
 */
final class ChangeAnalyzer
{
    public const MIGRATIONS = 'database/migrations';

    public const NODE_FILES = [
        'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', '.yarnrc.yml',
        '.nvmrc', '.node-version', 'bun.lock', 'bun.lockb',
    ];

    public function __construct(private readonly GitRepository $git)
    {
    }

    public function analyze(string $mirror, ?string $live, string $target, string $webDir): ChangeSet
    {
        $htaccess = ($webDir === '' ? '' : $webDir . '/') . '.htaccess';

        if ($live === null) {
            $migrations = [];
            foreach ($this->git->listFiles($mirror, $target, self::MIGRATIONS, true) as $path) {
                if (str_ends_with($path, '.php')) {
                    $migrations[] = self::migrationName($path);
                }
            }
            $lock = $this->json($mirror, $target, 'composer.lock');

            return new ChangeSet(
                null,
                $target,
                $this->git->log($mirror, null, $target, 50),
                $this->git->count($mirror, $target),
                0,
                false,
                false,
                $this->git->exists($mirror, $target, 'composer.json'),
                ComposerInspector::lockDiff(null, $lock),
                $migrations,
                [],
                [],
                true,
                false,
                true,
                [],
            );
        }

        if ($live === $target) {
            return new ChangeSet($live, $target, [], 0, 0, false, true, false, ComposerInspector::lockDiff(null, null), [], [], [], false, false, false, []);
        }

        $rewind = !$this->git->isAncestor($mirror, $live, $target);
        $changed = $this->git->changedFiles($mirror, $live, $target);
        $paths = array_map(static fn (array $row): string => $row[1], $changed);

        $added = $modified = $deleted = [];
        foreach ($changed as [$status, $path]) {
            if (!str_starts_with($path, self::MIGRATIONS . '/') || !str_ends_with($path, '.php')) {
                continue;
            }
            $name = self::migrationName($path);
            match ($status) {
                'A' => $added[] = $name,
                'D' => $deleted[] = $name,
                default => $modified[] = $name,
            };
        }

        $composerChanged = array_intersect($paths, ['composer.json', 'composer.lock']) !== [];
        $oldJson = $composerChanged ? $this->json($mirror, $live, 'composer.json') : null;
        $newJson = $composerChanged ? $this->json($mirror, $target, 'composer.json') : null;
        $phpChanged = $composerChanged && (($oldJson['require']['php'] ?? null) !== ($newJson['require']['php'] ?? null));
        $lockDiff = in_array('composer.lock', $paths, true)
            ? ComposerInspector::lockDiff($this->json($mirror, $live, 'composer.lock'), $this->json($mirror, $target, 'composer.lock'))
            : ComposerInspector::lockDiff(null, null);

        sort($added);

        return new ChangeSet(
            $live,
            $target,
            $this->git->log($mirror, $live, $target, 50),
            $this->git->count($mirror, $live . '..' . $target),
            $rewind ? $this->git->count($mirror, $target . '..' . $live) : 0,
            $rewind,
            false,
            $composerChanged,
            $lockDiff,
            $added,
            $modified,
            $deleted,
            array_intersect($paths, self::NODE_FILES) !== [],
            $phpChanged,
            in_array($htaccess, $paths, true),
            $paths,
        );
    }

    public static function migrationName(string $path): string
    {
        return basename($path, '.php');
    }

    /**
     * @return array<mixed>|null
     */
    private function json(string $mirror, string $sha, string $path): ?array
    {
        $raw = $this->git->show($mirror, $sha, $path);
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
