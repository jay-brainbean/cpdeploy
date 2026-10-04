<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Support\Environment;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every path the tool uses (§7.1). No other class builds path strings (ARC-06).
 *
 * A site has two folders (LAY-04): the tool's data about it in
 * ~/cpdeploy/sites/<site> (siteDir: site.yml, the mirror, logs, history,
 * backups, lock, state) and the site itself in ~/<site_dir>, by default
 * ~/cpdeploy_sites/<domain> (siteFilesDir: current, releases, shared).
 */
final class Paths
{
    public const MODE_ROOT = 0711;
    public const MODE_PRIVATE_DIR = 0700;
    public const MODE_SECRET_FILE = 0600;
    public const MODE_PUBLIC_FILE = 0644;
    public const MODE_PUBLIC_DIR = 0755;

    private readonly string $root;

    /** @var array<string, string> site => site_dir from site.yml (relative to home) */
    private array $siteDirs = [];

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

    /**
     * Names of the sites with a site.yml, from the folder listing alone.
     *
     * @return list<string>
     */
    public function siteNames(): array
    {
        $names = [];
        foreach (glob($this->sitesDir() . '/*/site.yml') ?: [] as $file) {
            $names[] = basename(dirname($file));
        }
        sort($names);

        return $names;
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

    /**
     * Records a site's site_dir (relative to home), so its folder is known
     * before site.yml exists (SiteCreator) and without re-reading it.
     */
    public function useSiteDir(string $site, string $siteDir): void
    {
        $this->siteDirs[$site] = trim($siteDir, '/');
    }

    /**
     * The site itself: current, releases/ and shared/ (LAY-04).
     */
    public function siteFilesDir(string $site): string
    {
        return $this->fromHome($this->siteDirs[$site] ??= $this->readSiteDir($site));
    }

    /**
     * The folder new sites go in: config.yml's sites_dir, under home.
     */
    public function sitesFilesRoot(string $sitesDir): string
    {
        return $this->fromHome($sitesDir);
    }

    /**
     * An absolute path for a path relative to the home folder.
     */
    public function fromHome(string $relative): string
    {
        return $this->home . '/' . trim($relative, '/');
    }

    public function releasesDir(string $site): string
    {
        return $this->siteFilesDir($site) . '/releases';
    }

    public function release(string $site, string $id): string
    {
        return $this->releasesDir($site) . '/' . $id;
    }

    public function current(string $site): string
    {
        return $this->siteFilesDir($site) . '/current';
    }

    public function sharedDir(string $site): string
    {
        return $this->siteFilesDir($site) . '/shared';
    }

    public function sharedEnv(string $site): string
    {
        return $this->sharedDir($site) . '/.env';
    }

    public function envBackupsDir(string $site): string
    {
        return $this->sharedDir($site) . '/env-backups';
    }

    /**
     * Optional Composer credentials (CMP-06); never written into a release.
     */
    public function sharedAuthJson(string $site): string
    {
        return $this->sharedDir($site) . '/auth.json';
    }

    /**
     * The captured cPanel PHP handler block (DOC-04).
     */
    public function handlerBlock(string $site): string
    {
        return $this->sharedDir($site) . '/php-handler.block';
    }

    /**
     * Docroot extras (.well-known, .user.ini, php.ini) linked into each release's web dir (REL-05).
     */
    public function sharedDocrootDir(string $site): string
    {
        return $this->sharedDir($site) . '/docroot';
    }

    public function releaseMeta(string $site, string $id): string
    {
        return $this->release($site, $id) . '/.release.json';
    }

    public function backupsDir(string $site): string
    {
        return $this->siteDir($site) . '/backups';
    }

    /**
     * backups/docroot-<ts>: the docroot as it was before the first go-live (DOC-02).
     */
    public function docrootBackup(string $site, string $stamp): string
    {
        return $this->backupsDir($site) . '/docroot-' . $stamp;
    }

    public function logsDir(string $site): string
    {
        return $this->siteDir($site) . '/logs';
    }

    public function history(string $site): string
    {
        return $this->siteDir($site) . '/history.jsonl';
    }

    private function readSiteDir(string $site): string
    {
        $file = $this->siteConfig($site);
        try {
            $data = is_file($file) ? Yaml::parseFile($file) : null;
        } catch (ParseException) {
            $data = null;
        }
        $siteDir = is_array($data) ? ($data['site_dir'] ?? null) : null;
        if (!is_string($siteDir) || trim($siteDir, '/') === '') {
            throw new RuntimeException("The site {$site} has no site_dir in {$file}");
        }

        return trim($siteDir, '/');
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
