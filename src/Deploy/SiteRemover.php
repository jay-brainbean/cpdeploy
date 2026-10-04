<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Database\DatabaseService;
use Cpdeploy\Docroot\DocrootDetach;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Git\DeployKeyService;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;
use Throwable;

/**
 * *Remove site* (§9.5.14). RM-01 order: the docroot action (DOC-07), the
 * GitHub key, the local key, the database, shared (moved to removed/ or
 * deleted), the releases and repo.git, the site folder, and an entry in
 * removed/history.jsonl. RM-02: a failed docroot action stops everything.
 * RM-03: a site that never went live leaves the docroot alone.
 */
final class SiteRemover
{
    /**
     * @param Closure(): GitHubApi $api
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly Masker $masker,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly DocrootManager $docroots,
        private readonly DocrootDetach $detach,
        private readonly DeployKeyService $keys,
        private readonly DatabaseService $databases,
        private readonly Closure $api,
    ) {
    }

    /**
     * The docroot actions that make sense for this site (empty = RM-03, none needed).
     *
     * @return list<string>
     */
    public function docrootActions(SiteConfig $config): array
    {
        if (!$this->docroots->isConverted($config)) {
            return [];
        }
        $actions = [];
        if ($this->releases->liveId($config->name()) !== null) {
            $actions[] = DocrootDetach::DETACH;
        }
        if ($this->detach->backup($config) !== null) {
            $actions[] = DocrootDetach::RESTORE;
        }
        $actions[] = DocrootDetach::EMPTY;

        return $actions;
    }

    /**
     * Whether the database may be dropped (DB-05: created by cpdeploy).
     */
    public function canDropDatabase(SiteConfig $config): bool
    {
        return $config->get('database.created_by_cpdeploy') === true && is_string($config->get('database.name'));
    }

    public function remove(string $name, RemoveOptions $options, Reporter $reporter): RemoveResult
    {
        $config = $this->sites->load($name);
        $lock = Lock::site($this->paths->siteLock($name), $name, 'remove', $this->system->userName(), Version::get(), $this->clock);
        $notes = [];
        $manual = [];
        try {
            $state = new StateFile($this->paths->stateFile($name), $this->fs);
            $data = $state->read();
            if ($data !== null) {
                throw Recovery::interrupted($name, $data);
            }

            // 1. The docroot (RM-02: a failure here stops before anything is deleted).
            $actions = $this->docrootActions($config);
            if ($actions !== []) {
                if ($options->docroot === null || !in_array($options->docroot, $actions, true)) {
                    throw new CpdeployException(ErrorCode::USAGE, "What should happen to {$config->domain()}? Choose one of: " . implode(', ', array_map(static fn (string $a): string => '--' . self::flag($a), $actions)), 'Nothing was removed.');
                }
                $reporter->start('Docroot');
                try {
                    $notes[] = $this->detach->apply($config, $options->docroot);
                } catch (CpdeployException $e) {
                    $reporter->fail($e->getMessage());
                    throw $e;
                }
                $reporter->succeed($options->docroot);
            } else {
                $notes[] = "{$config->docroot()} was left as it is (the site never went live)";
            }

            // 2–3. The deploy key: on GitHub, then locally.
            if ($options->removeKey) {
                $reporter->start('Deploy key');
                try {
                    $instruction = $this->keys->remove($config->repo(), $name, $this->system->userName(), $this->system->hostName(), ($this->api)(), $config->deployKeyId(), true);
                    if ($instruction !== null) {
                        $manual[] = $instruction;
                    }
                    $reporter->succeed($instruction === null ? 'removed from GitHub and ~/.ssh' : 'removed from ~/.ssh');
                } catch (Throwable $e) {
                    @unlink($this->keys->keyPath($name));
                    @unlink($this->keys->keyPath($name) . '.pub');
                    $manual[] = "Couldn't delete the key on GitHub ({$e->getMessage()}): delete it under {$config->repo()->keysUrl()}";
                    $reporter->warn('Deploy key removed from ~/.ssh only');
                }
            } else {
                $notes[] = 'deploy key kept: ' . $this->keys->keyPath($name);
            }

            // 4. The database (DB-05).
            if ($options->dropDatabase) {
                if (!$this->canDropDatabase($config)) {
                    $manual[] = 'The database was not created by cpdeploy, so it was kept.';
                } else {
                    $database = (string) $config->get('database.name');
                    $user = $config->get('database.user');
                    $reporter->start('Database');
                    try {
                        $this->databases->drop($database, is_string($user) ? $user : null);
                        $reporter->succeed($database);
                        $notes[] = "database {$database} dropped";
                    } catch (Throwable $e) {
                        $reporter->warn("Couldn't drop the database {$database}: {$e->getMessage()}");
                        $manual[] = "Remove the database {$database} in cPanel → MySQL Databases.";
                    }
                }
            }

            // 5. shared/: moved to removed/<site>-<ts>/, or deleted with the site folder.
            $shared = $this->paths->sharedDir($name);
            $keptAt = null;
            if (!$options->deleteShared && is_dir($shared)) {
                $keptAt = $this->paths->removedDir() . '/' . $name . '-' . $this->clock->stamp();
                $this->fs->ensureDir($this->paths->removedDir(), Paths::MODE_PRIVATE_DIR);
                $this->fs->ensureDir($keptAt, Paths::MODE_PRIVATE_DIR);
                if (!@rename($shared, $keptAt . '/shared')) {
                    throw new CpdeployException(ErrorCode::INTERNAL, "Couldn't move {$shared} to {$keptAt}", "The docroot was already changed; nothing was deleted. Move shared/ by hand, then run: cpdeploy remove {$name} --delete-shared", exitCode: 1);
                }
                @copy($this->paths->siteConfig($name), $keptAt . '/site.yml');
                @chmod($keptAt . '/site.yml', Paths::MODE_SECRET_FILE);
                $notes[] = ".env and uploads moved to {$keptAt}";
            }

            // 6. Releases and the mirror (FS-03), then 7. both site folders (LAY-04).
            $reporter->start('Releases and the repository copy');
            @unlink($this->paths->current($name));
            foreach ($this->releases->all($name) as $release) {
                $this->fs->deleteRelease($name, $release->dir);
            }
            $this->fs->deleteTree($this->paths->mirror($name));
            $this->fs->deleteSiteFiles($name);
            $this->fs->deleteSiteFolder($name);
            $reporter->succeed('');

            // 8. History, outside the site folder.
            try {
                $this->fs->ensureDir($this->paths->removedDir(), Paths::MODE_PRIVATE_DIR);
                Log::appendHistory($this->paths->removedDir() . '/history.jsonl', [
                    'schema' => 1,
                    'ts' => $this->clock->iso(),
                    'site' => $name,
                    'action' => 'remove',
                    'domain' => $config->domain(),
                    'docroot' => $options->docroot,
                    'kept_at' => $keptAt,
                    'user' => $this->system->userName(),
                    'tool' => Version::get(),
                    'notes' => [...$notes, ...$manual],
                ], $this->masker);
            } catch (Throwable) {
                // The site is gone either way.
            }

            return new RemoveResult($notes, $manual, $keptAt);
        } finally {
            $lock->release();
        }
    }

    public static function flag(string $action): string
    {
        return match ($action) {
            DocrootDetach::RESTORE => 'restore-backup',
            default => $action,
        };
    }
}
