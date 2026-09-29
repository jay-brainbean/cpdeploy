<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Support\Environment;

/**
 * Every path the tool uses (§7.1). No other class builds path strings (ARC-06).
 */
final class Paths
{
    public const MODE_ROOT = 0711;
    public const MODE_PRIVATE_DIR = 0700;
    public const MODE_SECRET_FILE = 0600;
    public const MODE_PUBLIC_FILE = 0644;
    public const MODE_PUBLIC_DIR = 0755;

    private readonly string $root;

    public function __construct(private readonly string $home, ?string $root = null)
    {
        $this->root = rtrim($root ?? $home . '/cpdeploy', '/');
    }

    /**
     * CPDEPLOY_HOME overrides the root only when CPDEPLOY_TESTING=1 (§7.1).
     */
    public static function fromEnvironment(Environment $env): self
    {
        return new self($env->home(), $env->testing('CPDEPLOY_HOME'));
    }

    public function home(): string
    {
        return $this->home;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function launcher(): string
    {
        return $this->home . '/bin/cpdeploy';
    }

    public function appDir(): string
    {
        return $this->root . '/app';
    }

    public function phar(): string
    {
        return $this->appDir() . '/cpdeploy.phar';
    }

    public function previousPhar(): string
    {
        return $this->appDir() . '/cpdeploy.phar.prev';
    }

    public function configFile(): string
    {
        return $this->root . '/config.yml';
    }

    public function secretsDir(): string
    {
        return $this->root . '/secrets';
    }

    public function tokenFile(): string
    {
        return $this->secretsDir() . '/github-token';
    }

    public function knownHosts(): string
    {
        return $this->root . '/known_hosts';
    }

    public function toolsDir(): string
    {
        return $this->root . '/tools';
    }

    public function toolsLock(): string
    {
        return $this->toolsDir() . '/.lock';
    }

    public function composerDir(): string
    {
        return $this->toolsDir() . '/composer';
    }

    public function nodeDir(): string
    {
        return $this->toolsDir() . '/node';
    }

    public function packageManagerDir(): string
    {
        return $this->toolsDir() . '/pm';
    }

    public function tmpDir(): string
    {
        return $this->root . '/tmp';
    }

    public function removedDir(): string
    {
        return $this->root . '/removed';
    }

    public function sitesDir(): string
    {
        return $this->root . '/sites';
    }

    public function siteDir(string $site): string
    {
        return $this->sitesDir() . '/' . $site;
    }

    public function siteConfig(string $site): string
    {
        return $this->siteDir($site) . '/site.yml';
    }

    public function siteLock(string $site): string
    {
        return $this->siteDir($site) . '/.lock';
    }

    public function stateFile(string $site): string
    {
        return $this->siteDir($site) . '/.deploy-state.json';
    }

    public function mirror(string $site): string
    {
        return $this->siteDir($site) . '/repo.git';
    }

    public function releasesDir(string $site): string
    {
        return $this->siteDir($site) . '/releases';
    }

    public function release(string $site, string $id): string
    {
        return $this->releasesDir($site) . '/' . $id;
    }

    public function current(string $site): string
    {
        return $this->siteDir($site) . '/current';
    }

    public function sharedDir(string $site): string
    {
        return $this->siteDir($site) . '/shared';
    }

    public function sharedEnv(string $site): string
    {
        return $this->sharedDir($site) . '/.env';
    }

    public function envBackupsDir(string $site): string
    {
        return $this->sharedDir($site) . '/env-backups';
    }

    public function backupsDir(string $site): string
    {
        return $this->siteDir($site) . '/backups';
    }

    public function logsDir(string $site): string
    {
        return $this->siteDir($site) . '/logs';
    }

    public function history(string $site): string
    {
        return $this->siteDir($site) . '/history.jsonl';
    }

    public function sshDir(): string
    {
        return $this->home . '/.ssh';
    }

    public function deployKey(string $site): string
    {
        return $this->sshDir() . '/cpdeploy_' . $site;
    }

    /**
     * Directories that recursive deletes may operate inside (FS-03).
     *
     * @return list<string>
     */
    public function deletableRoots(): array
    {
        return [$this->tmpDir(), $this->toolsDir()];
    }

    /**
     * Folders created at first run with their modes (LAY-02).
     *
     * @return array<string, int>
     */
    public function skeleton(): array
    {
        return [
            $this->root => self::MODE_ROOT,
            $this->appDir() => self::MODE_PUBLIC_DIR,
            $this->secretsDir() => self::MODE_PRIVATE_DIR,
            $this->toolsDir() => self::MODE_ROOT,
            $this->tmpDir() => self::MODE_PRIVATE_DIR,
            $this->removedDir() => self::MODE_PRIVATE_DIR,
            $this->sitesDir() => self::MODE_ROOT,
        ];
    }
}
