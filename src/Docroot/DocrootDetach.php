<?php

declare(strict_types=1);

namespace Cpdeploy\Docroot;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * DOC-07, used by *Remove site*: the domain's folder stops depending on
 * cpdeploy. *Restore* puts the pre-cpdeploy folder back, *Detach* copies the
 * live release to ~/<site>-app (real files) and points the docroot there,
 * *Empty* leaves a folder with only the shared .well-known.
 */
final class DocrootDetach
{
    public const RESTORE = 'restore';
    public const DETACH = 'detach';
    public const EMPTY = 'empty';

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly ReleaseManager $releases,
        private readonly DocrootManager $docroots,
    ) {
    }

    /**
     * The backup of the folder from before the first go-live, when it still exists.
     */
    public function backup(SiteConfig $config): ?string
    {
        $recorded = $config->get('domain.backup');
        if (!is_string($recorded) || $recorded === '') {
            return null;
        }
        $path = $this->paths->siteDir($config->name()) . '/' . $recorded;

        return is_dir($path) && !is_link($path) ? $path : null;
    }

    /**
     * ~/<site>-app, or ~/<site>-app-2, … when that exists.
     */
    public function appDir(string $site): string
    {
        $base = $this->paths->home() . '/' . $site . '-app';
        $dir = $base;
        for ($i = 2; file_exists($dir) || is_link($dir); $i++) {
            $dir = $base . '-' . $i;
        }

        return $dir;
    }

    /**
     * Runs one action. Returns what the docroot is now, for the summary.
     */
    public function apply(SiteConfig $config, string $action): string
    {
        $docroot = $config->docroot();
        if (!$this->docroots->isConverted($config)) {
            throw new CpdeployException(ErrorCode::DOCROOT_UNSAFE, "{$docroot} isn't a link managed by cpdeploy", 'Nothing was changed. See: cpdeploy check ' . $config->name());
        }

        return match ($action) {
            self::RESTORE => $this->restore($config),
            self::DETACH => $this->detach($config),
            self::EMPTY => $this->empty($config),
            default => throw new CpdeployException(ErrorCode::USAGE, "Unknown docroot action {$action}", 'Use --detach, --restore-backup or --empty.'),
        };
    }

    private function restore(SiteConfig $config): string
    {
        $backup = $this->backup($config);
        if ($backup === null) {
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, 'The folder from before cpdeploy is no longer in backups/', 'Choose to detach or leave an empty folder instead. Nothing was changed.');
        }
        $this->replaceLink($config->docroot(), $backup);

        return "{$config->docroot()} is the folder from before cpdeploy again";
    }

    private function detach(SiteConfig $config): string
    {
        $site = $config->name();
        $live = $this->releases->live($site);
        if ($live === null) {
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "{$site} has no live release to keep running", 'Choose to restore the old folder or leave an empty one. Nothing was changed.');
        }
        $app = $this->appDir($site);
        $this->fs->ensureDir($app, Paths::MODE_ROOT);
        try {
            $this->fs->copyTree($live->dir . '/.', $app);
            $this->materialise($live->dir, $app, $site);
            @unlink($app . '/.release.json');
            @unlink($app . '/.release-manifest');
        } catch (RuntimeException $e) {
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "Couldn't copy the live release to {$app}: " . $e->getMessage(), "Nothing was changed; remove {$app} and try again.");
        }
        $web = $config->webDir() === '' ? $app : $app . '/' . $config->webDir();
        try {
            $this->fs->linkRelative($config->docroot(), $web);
        } catch (RuntimeException $e) {
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "Couldn't point {$config->docroot()} at {$web}: " . $e->getMessage(), "The site still runs from cpdeploy. {$app} was left in place.");
        }

        return "{$config->docroot()} → {$web} (the site keeps running without cpdeploy)";
    }

    private function empty(SiteConfig $config): string
    {
        $docroot = $config->docroot();
        $tmp = dirname($docroot) . '/.' . basename($docroot) . '.cpd-empty-' . bin2hex(random_bytes(4));
        $this->fs->ensureDir($tmp, Paths::MODE_ROOT);
        $wellKnown = $this->paths->sharedDocrootDir($config->name()) . '/.well-known';
        try {
            if (is_dir($wellKnown)) {
                $this->fs->copyTree($wellKnown, $tmp . '/.well-known');
            }
        } catch (RuntimeException $e) {
            @rmdir($tmp);
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "Couldn't prepare the empty folder: " . $e->getMessage(), 'Nothing was changed.');
        }
        $this->replaceLink($docroot, $tmp);

        return "{$docroot} is an empty folder (the site is offline)";
    }

    /**
     * Swaps the docroot symlink for a real folder. The link is put back when the
     * rename fails, so the site keeps running (RM-02).
     */
    private function replaceLink(string $docroot, string $folder): void
    {
        $target = readlink($docroot);
        if ($target === false || !@unlink($docroot)) {
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "Couldn't remove the link {$docroot}", 'Nothing was changed.');
        }
        if (!@rename($folder, $docroot)) {
            @symlink($target, $docroot);
            throw new CpdeployException(ErrorCode::DOCROOT_MOVE, "Couldn't move {$folder} to {$docroot}", 'The link was put back; the site still runs from cpdeploy.');
        }
    }

    /**
     * The copy of a release has links into shared/ (and absolute links into the
     * release). Links that leave the release become real copies; links to the
     * release itself are re-pointed inside the copy.
     */
    private function materialise(string $release, string $app, string $site): void
    {
        $siteDir = Fs::normalize($this->paths->siteDir($site));
        $links = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($release, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $path => $info) {
            if (is_link((string) $path)) {
                $links[] = (string) $path;
            }
        }
        // Parents first, so a copied folder's own links are handled by the copy.
        sort($links);
        $done = [];
        foreach ($links as $link) {
            foreach ($done as $parent) {
                if (str_starts_with($link, $parent . '/')) {
                    continue 2;
                }
            }
            $raw = (string) readlink($link);
            $target = Fs::normalize(str_starts_with($raw, '/') ? $raw : dirname($link) . '/' . $raw);
            $copy = $app . substr($link, strlen($release));
            if (Fs::isInside($target, Fs::normalize($release)) || $target === Fs::normalize($release)) {
                $this->fs->linkRelative($copy, $app . substr($target, strlen(Fs::normalize($release))));
            } elseif (Fs::isInside($target, $siteDir)) {
                @unlink($copy);
                $real = realpath($target);
                if ($real !== false) {
                    $this->fs->copyTree($real, $copy);
                    $done[] = $link;
                }
            }
        }
    }
}
