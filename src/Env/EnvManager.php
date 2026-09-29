<?php

declare(strict_types=1);

namespace Cpdeploy\Env;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;

/**
 * A site's shared/.env (§7.12): reading with secrets masked (ENV-05), changes
 * saved with a backup (ENV-06), backups restored, and "apply to the live site"
 * (ENV-08). The `env` command and Manage site → Environment both use it (ARC-03).
 */
final class EnvManager
{
    /** ENV-06: backups kept per site. */
    public const BACKUPS_KEEP = 10;

    public const MASK = '••••••';

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly Masker $masker,
        private readonly SystemInfo $system,
        private readonly GlobalConfig $config,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly Artisan $artisan,
        private readonly PhpService $php,
        private readonly Finisher $finisher,
    ) {
    }

    public function exists(string $site): bool
    {
        return is_file($this->paths->sharedEnv($site));
    }

    public function read(string $site): EnvFile
    {
        $this->sites->load($site);
        $file = $this->paths->sharedEnv($site);
        @chmod($file, Paths::MODE_SECRET_FILE);
        $env = EnvFile::fromFile($file);
        $this->masker->addEnv($env->all());

        return $env;
    }

    /**
     * ENV-05: key → value, secrets masked unless $reveal.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function listing(string $site, bool $reveal): array
    {
        $out = [];
        foreach ($this->read($site)->all() as $key => $value) {
            $out[] = [$key, $reveal || !Masker::isSecretKey($key) || $value === '' ? $value : self::MASK];
        }

        return $out;
    }

    /**
     * ENV-03 / ENV-04 then ENV-06. Returns the backup made (null when .env was new).
     */
    public function set(string $site, string $key, string $value, bool $lockHeld = false): ?string
    {
        $env = $this->exists($site) ? $this->read($site) : EnvFile::parse('');
        $this->masker->addEnv([$key => $value]);

        return $this->save($site, $env->set($key, $value)->toString(), "set {$key}", $lockHeld);
    }

    public function unset(string $site, string $key): ?string
    {
        $env = $this->read($site);
        if (!$env->has($key)) {
            throw new CpdeployException(ErrorCode::USAGE, ".env has no {$key}", "List the keys: cpdeploy env {$site} list");
        }

        return $this->save($site, $env->unset($key)->toString(), "unset {$key}");
    }

    /**
     * ENV-06: parse check → backup of the current file → atomic write (600), under
     * the site lock; a history entry `env-change` records what changed (never a value).
     * Returns the backup made (null when .env was new).
     */
    public function save(string $site, string $content, string $what, bool $lockHeld = false): ?string
    {
        $parsed = EnvFile::parse($content, '.env');
        $this->masker->addEnv($parsed->all());
        // $lockHeld: called from inside an operation that holds the site lock (a deploy).
        $lock = $lockHeld ? null : Lock::site($this->paths->siteLock($site), $site, 'env', $this->system->userName(), Version::get(), $this->clock);
        try {
            $file = $this->paths->sharedEnv($site);
            $backup = null;
            if (is_file($file)) {
                $dir = $this->paths->envBackupsDir($site);
                $this->fs->ensureDir($this->paths->sharedDir($site), Paths::MODE_ROOT);
                $this->fs->ensureDir($dir, Paths::MODE_PRIVATE_DIR);
                $backup = $dir . '/.env.' . $this->clock->stamp();
                for ($i = 2; file_exists($backup); $i++) {
                    $backup = $dir . '/.env.' . $this->clock->stamp() . '-' . $i;
                }
                $this->fs->writeAtomic($backup, (string) file_get_contents($file), Paths::MODE_SECRET_FILE);
                $this->pruneBackups($site);
            } else {
                $this->fs->ensureDir($this->paths->sharedDir($site), Paths::MODE_ROOT);
            }
            $this->fs->writeAtomic($file, $content, Paths::MODE_SECRET_FILE);
            $this->finisher->history($site, 'env-change', 'success', 0, $this->releases->liveId($site), null, null, null, 0.0, null, $this->system->userName(), [$what]);

            return $backup;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Backup file names, newest first.
     *
     * @return list<string>
     */
    public function backups(string $site): array
    {
        $files = array_map('basename', glob($this->paths->envBackupsDir($site) . '/.env.*') ?: []);
        rsort($files);

        return $files;
    }

    public function backupContent(string $site, string $name): string
    {
        if (!in_array($name, $this->backups($site), true)) {
            throw new CpdeployException(ErrorCode::USAGE, "There is no .env backup {$name}", "List them with: cpdeploy env {$site} restore");
        }

        return (string) file_get_contents($this->paths->envBackupsDir($site) . '/' . $name);
    }

    /**
     * Restores a backup (the current file is backed up first, ENV-06).
     */
    public function restore(string $site, string $name): ?string
    {
        return $this->save($site, $this->backupContent($site, $name), "restored {$name}");
    }

    /**
     * ENV-08: `php artisan optimize` in the live release with its PHP, because
     * Laravel caches config per release. Returns false (with a message) when there
     * is nothing to apply it to.
     */
    public function apply(string $site, Reporter $reporter): bool
    {
        $config = $this->sites->load($site);
        $live = $this->releases->live($site);
        if (!$config->isLaravel() || $live === null) {
            $reporter->info($live === null ? 'Nothing to apply yet: the site has no live release.' : 'Only Laravel sites cache their configuration; nothing to apply.');

            return false;
        }
        $lock = Lock::site($this->paths->siteLock($site), $site, 'env apply', $this->system->userName(), Version::get(), $this->clock);
        try {
            $php = $this->php->forRelease($live->phpBinary(), $this->php->resolve($config->phpVersion(), $config->phpFamily()), $reporter);
            $reporter->start('Apply .env to the live site');
            $options = (new RunOptions(cwd: $live->dir, timeout: (float) $this->config->timeout('artisan'), label: 'artisan optimize'))
                ->reporting(fn (string $line) => $reporter->line($line), fn () => $reporter->tick());
            $result = $this->artisan->run($live->dir, $php, ['optimize'], $options);
            if (!$result->successful()) {
                $reporter->fail('php artisan optimize failed');
                throw new CpdeployException(
                    ErrorCode::ARTISAN,
                    'php artisan optimize failed in the live release: ' . trim(implode("\n", array_slice(explode("\n", trim($result->output())), -5))),
                    'The .env change is saved; the live site still uses its cached config. Fix the problem and run: cpdeploy env ' . $site . ' apply',
                    liveAffected: true,
                );
            }
            $reporter->succeed($live->id);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function pruneBackups(string $site): void
    {
        $dir = $this->paths->envBackupsDir($site);
        foreach (array_slice($this->backups($site), self::BACKUPS_KEEP) as $old) {
            if (!is_link($dir . '/' . $old)) {
                @unlink($dir . '/' . $old);
            }
        }
    }
}
