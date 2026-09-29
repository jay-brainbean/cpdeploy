<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Log;

/**
 * Read-only facts about sites and releases, in the §10.5 JSON shapes. Shared by
 * `status` / `releases` and (from M5) the main menu's site table (ARC-03).
 */
final class SiteStatus
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
    ) {
    }

    /**
     * §10.5 status entry for one site.
     *
     * @return array<string, mixed>
     */
    public function site(string $name): array
    {
        $entry = ['name' => $name, 'type' => null, 'domain' => null, 'branch' => null];
        try {
            $config = $this->sites->load($name);
            $entry['type'] = $config->type();
            $entry['domain'] = $config->domain();
            $entry['branch'] = $config->branch();
        } catch (CpdeployException $e) {
            $entry['error'] = strtok($e->getMessage(), "\n");
        }

        $live = $this->releases->live($name);
        $entry['live'] = $live === null ? null : [
            'release' => $live->id,
            'commit' => $live->commit(),
            'message' => $live->message(),
            'deployed_at' => $live->get('activated_at') ?? $live->createdAt(),
            'php' => $live->get('php.version'),
            'node' => $live->get('node.version'),
        ];
        $history = Log::readHistory($this->paths->history($name), 1);
        $last = $history[0] ?? null;
        $entry['last'] = $last === null ? null : [
            'action' => $last['action'] ?? null,
            'result' => $last['result'] ?? null,
            'at' => $last['ts'] ?? null,
        ];
        $entry['maintenance'] = $live !== null && Maintenance::isDown($live->dir);
        $locked = Lock::isHeld($this->paths->siteLock($name));
        $entry['locked'] = $locked;
        $entry['interrupted'] = !$locked && is_file($this->paths->stateFile($name));

        return $entry;
    }

    /**
     * @return array<string, mixed> {schema, sites: [...]}
     */
    public function all(): array
    {
        return ['schema' => 1, 'sites' => array_map(fn (string $n): array => $this->site($n), $this->sites->names())];
    }

    /**
     * §10.5 releases document. $sizes: measure each release with du (FS-05).
     *
     * @return array<string, mixed>
     */
    public function releases(string $name, bool $sizes = true): array
    {
        $this->sites->load($name);
        $liveId = $this->releases->liveId($name);
        $list = [];
        foreach ($this->releases->all($name) as $release) {
            $list[] = [
                'id' => $release->id,
                'status' => $release->id === $liveId ? Release::LIVE : $release->status(),
                'protected' => $release->isProtected(),
                'commit' => $release->commit(),
                'short' => $release->short(),
                'message' => $release->message(),
                'created_at' => $release->createdAt(),
                'php' => $release->get('php.version'),
                'size_kb' => $sizes ? $this->fs->diskUsageKb($release->dir) : null,
            ];
        }

        return ['schema' => 1, 'site' => $name, 'live' => $liveId, 'releases' => $list];
    }
}
