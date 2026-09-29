<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * Creates, lists, protects, prunes and deletes a site's releases (§8.5, PR-01).
 */
final class ReleaseManager
{
    /** A `building` release older than this, and not this run's, is stale (PR-01). */
    public const STALE_BUILDING = 3600;

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Newest first.
     *
     * @return list<Release>
     */
    public function all(string $site): array
    {
        $dir = $this->paths->releasesDir($site);
        $out = [];
        if (!is_dir($dir)) {
            return []; // never deployed
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_contains($entry, '.cpd-tmp-')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $out[] = Release::load($path);
            }
        }
        usort($out, static fn (Release $a, Release $b): int => strcmp($b->id, $a->id));

        return $out;
    }

    /**
     * The release `current` points at, or null (first deploy, or no link).
     */
    public function liveId(string $site): ?string
    {
        $current = $this->paths->current($site);
        if (!is_link($current)) {
            return null;
        }
        $target = readlink($current);
        if ($target === false) {
            return null;
        }
        $id = basename($target);

        return is_dir($this->paths->release($site, $id)) ? $id : null;
    }

    public function live(string $site): ?Release
    {
        $id = $this->liveId($site);

        return $id === null ? null : Release::load($this->paths->release($site, $id));
    }

    public function find(string $site, string $id): ?Release
    {
        if (preg_match('/^\d{8}-\d{6}(-\d+)?$/', $id) !== 1) {
            return null;
        }
        $dir = $this->paths->release($site, $id);

        return is_dir($dir) && !is_link($dir) ? Release::load($dir) : null;
    }

    public function get(string $site, string $id): Release
    {
        $release = $this->find($site, $id);
        if ($release === null) {
            throw new CpdeployException(ErrorCode::USAGE, "{$site} has no release {$id}", "List them with: cpdeploy releases {$site}");
        }

        return $release;
    }

    /**
     * B1: a new release folder (755) named after the current UTC time; an id
     * collision gets a -2, -3, … suffix.
     */
    public function create(string $site): Release
    {
        $this->fs->ensureDir($this->paths->releasesDir($site), Paths::MODE_ROOT);
        $base = $this->clock->stamp();
        $id = $base;
        for ($i = 2; file_exists($this->paths->release($site, $id)) || is_link($this->paths->release($site, $id)); $i++) {
            $id = $base . '-' . $i;
        }
        $dir = $this->paths->release($site, $id);
        if (!@mkdir($dir, Paths::MODE_PUBLIC_DIR)) {
            throw new RuntimeException("Cannot create release folder {$dir}");
        }
        @chmod($dir, Paths::MODE_PUBLIC_DIR);

        return new Release($id, $dir, ['schema' => 1, 'id' => $id, 'status' => Release::BUILDING, 'protected' => false]);
    }

    /**
     * PR-01 selection. Keeps the live release, protected releases and the newest
     * $keep releases that are ready or live; deletes every other ready release,
     * every failed one, and building/unknown ones older than an hour that aren't
     * this run's ($currentId).
     *
     * @param list<Release> $releases newest first
     * @return array{keep: list<Release>, delete: list<Release>}
     */
    public static function pruneSelection(array $releases, ?string $liveId, int $keep, ?string $currentId, int $now): array
    {
        $keepList = [];
        $delete = [];
        // The live release always takes one of the `keep` slots, even when newer
        // releases exist (after a rollback).
        $good = 0;
        foreach ($releases as $release) {
            if ($release->id === $liveId) {
                $good = 1;
            }
        }
        foreach ($releases as $release) {
            $status = $release->status();
            $isLive = $release->id === $liveId;
            if ($isLive || $release->id === $currentId) {
                if (!$isLive && in_array($status, [Release::READY, Release::LIVE], true)) {
                    $good++;
                }
                $keepList[] = $release;
                continue;
            }
            // Protected releases are kept in addition to `keep` (§8.2).
            if ($release->isProtected()) {
                $keepList[] = $release;
                continue;
            }
            if (in_array($status, [Release::READY, Release::LIVE], true)) {
                $good++;
                if ($good <= $keep) {
                    $keepList[] = $release;
                } else {
                    $delete[] = $release;
                }
                continue;
            }
            if ($status === Release::FAILED) {
                $delete[] = $release;
                continue;
            }
            // building / unknown
            if ($release->ageSeconds($now) > self::STALE_BUILDING) {
                $delete[] = $release;
            } else {
                $keepList[] = $release;
            }
        }

        return ['keep' => $keepList, 'delete' => $delete];
    }

    /**
     * PR-01: deletes what pruneSelection() selects (FS-03 safe delete).
     * Returns kept count, removed count, freed KB and any problems (PR-04: warnings).
     *
     * @return array{kept: int, removed: int, freed_kb: int, problems: list<string>}
     */
    public function prune(string $site, int $keep, ?string $currentId = null): array
    {
        $selection = self::pruneSelection($this->all($site), $this->liveId($site), $keep, $currentId, $this->clock->now()->getTimestamp());
        $removed = 0;
        $freed = 0;
        $problems = [];
        foreach ($selection['delete'] as $release) {
            $size = $this->fs->diskUsageKb($release->dir) ?? 0;
            try {
                $this->fs->deleteTree($release->dir);
                $removed++;
                $freed += $size;
            } catch (RuntimeException $e) {
                $problems[] = "Couldn't remove release {$release->id}: " . $e->getMessage();
            }
        }

        return ['kept' => count($selection['keep']), 'removed' => $removed, 'freed_kb' => $freed, 'problems' => $problems];
    }

    public function protect(string $site, string $id, bool $protected): Release
    {
        $release = $this->get($site, $id);
        $release->set('protected', $protected);
        $release->save($this->fs);

        return $release;
    }

    /**
     * Deletes one release. Never the live one (INV-02).
     */
    public function delete(string $site, string $id): void
    {
        $release = $this->get($site, $id);
        if ($release->id === $this->liveId($site)) {
            throw new CpdeployException(ErrorCode::USAGE, "{$id} is the live release and can't be deleted", 'Deploy or roll back to another release first.');
        }
        $this->fs->deleteTree($release->dir);
    }

    /**
     * Records the switch in the metadata: $new live (+ activated_at), the previous live release ready.
     */
    public function markLive(Release $new, ?Release $previous): void
    {
        $new->set('status', Release::LIVE);
        $new->set('activated_at', $this->clock->iso());
        $new->save($this->fs);
        if ($previous !== null && $previous->id !== $new->id) {
            $previous->set('status', Release::READY);
            $previous->save($this->fs);
        }
    }
}
