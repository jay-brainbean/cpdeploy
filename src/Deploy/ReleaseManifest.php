<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Fs;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * DOC-06: `.release-manifest` (path + SHA-1 of every file a release got from its
 * build), written at B10 and compared with the live files at the next deploy's
 * preflight (PRE-18) to find files changed directly on the server.
 */
final class ReleaseManifest
{
    public const FILE = '.release-manifest';

    /** Folders whose contents change at runtime or are rebuilt (DOC-06). */
    public const EXCLUDED = ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build'];

    /** PRE-18 SHOULD finish within this many seconds; a slower check is skipped. */
    public const BUDGET = 5.0;

    /** How many changed files the warning lists. */
    public const LIST = 20;

    public function __construct(private readonly Fs $fs)
    {
    }

    public function write(string $releaseDir): void
    {
        $lines = [];
        foreach ($this->hashes($releaseDir, INF) ?? [] as $path => $sha) {
            $lines[] = $sha . '  ' . $path;
        }
        $this->fs->writeAtomic($releaseDir . '/' . self::FILE, implode("\n", $lines) . "\n", Paths::MODE_PUBLIC_FILE);
    }

    /**
     * Files changed or added in $releaseDir since its manifest was written (deleted
     * files don't matter: they aren't carried over anyway). Null when the release has
     * no manifest (built before M4) or the check took longer than $budget seconds.
     *
     * @param list<string> $ignore paths left to another check (the web dir's .htaccess, DOC-05)
     * @return array{changed: list<string>, added: list<string>}|null
     */
    public function drift(string $releaseDir, array $ignore = [], float $budget = self::BUDGET): ?array
    {
        $recorded = $this->read($releaseDir);
        if ($recorded === null) {
            return null;
        }
        $now = $this->hashes($releaseDir, $budget);
        if ($now === null) {
            return null;
        }
        $changed = [];
        $added = [];
        foreach ($now as $path => $sha) {
            if (in_array($path, $ignore, true)) {
                continue;
            }
            if (!isset($recorded[$path])) {
                $added[] = $path;
            } elseif ($recorded[$path] !== $sha) {
                $changed[] = $path;
            }
        }

        return ['changed' => $changed, 'added' => $added];
    }

    /**
     * Whether a manifest exists (a drift of null then means "too slow").
     */
    public static function exists(string $releaseDir): bool
    {
        return is_file($releaseDir . '/' . self::FILE);
    }

    /**
     * @return array<string, string>|null path => sha1
     */
    private function read(string $releaseDir): ?array
    {
        $raw = @file_get_contents($releaseDir . '/' . self::FILE);
        if (!is_string($raw)) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([0-9a-f]{40})  (.+)$/', $line, $m) === 1) {
                $out[$m[2]] = $m[1];
            }
        }

        return $out;
    }

    /**
     * SHA-1 of every regular file (never following symlinks), sorted by path; null
     * when it takes longer than $budget seconds.
     *
     * @return array<string, string>|null
     */
    private function hashes(string $dir, float $budget): ?array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $started = microtime(true);
        $out = [];
        $prefix = strlen(rtrim($dir, '/')) + 1;
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                static function (\SplFileInfo $file) use ($prefix): bool {
                    $path = substr($file->getPathname(), $prefix);
                    if ($file->isLink()) {
                        return false;
                    }
                    foreach (self::EXCLUDED as $excluded) {
                        if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                            return false;
                        }
                    }

                    return !($file->isFile() && self::metadata($file->getFilename()));
                },
            ),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $sha = @sha1_file($file->getPathname());
            if ($sha !== false) {
                $out[substr($file->getPathname(), $prefix)] = $sha;
            }
            if (microtime(true) - $started > $budget) {
                return null;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * cpdeploy's own files in a release: .release.json, the manifest, markers (.cpd-*).
     */
    private static function metadata(string $name): bool
    {
        return $name === Release::META || $name === self::FILE || str_starts_with($name, '.cpd-');
    }
}
