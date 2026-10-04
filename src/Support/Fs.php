<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Cpdeploy\Config\Paths;
use RuntimeException;

/**
 * Filesystem operations with the safety rules of §7.16.
 */
final class Fs
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Shell $shell,
    ) {
    }

    /**
     * FS-01: write to a temp file with the final mode, fsync, then rename over the target.
     */
    public function writeAtomic(string $file, string $contents, int $mode = Paths::MODE_SECRET_FILE): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            throw new RuntimeException("Folder does not exist: {$dir}");
        }
        $tmp = $file . '.cpd-tmp-' . bin2hex(random_bytes(6));

        // Create the file with its final mode directly (FS-06), never wider first.
        $oldUmask = umask(0777 & ~$mode & 0777);
        try {
            $handle = @fopen($tmp, 'x');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false) {
            throw new RuntimeException("Cannot create {$tmp}");
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException("Cannot write {$tmp}");
            }
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        chmod($tmp, $mode);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot move {$tmp} over {$file}");
        }
    }

    /**
     * FS-02: point $link at $target atomically. Never unlinks first (unlike ln -sfn).
     * $target is written exactly as given; pass a relative path (LAY-01).
     */
    public function swapSymlink(string $link, string $target): void
    {
        if (file_exists($link) && !is_link($link)) {
            throw new RuntimeException("{$link} exists and is not a symlink");
        }
        $tmp = $link . '.cpd-tmp-' . bin2hex(random_bytes(6));
        if (!@symlink($target, $tmp)) {
            throw new RuntimeException("Cannot create symlink {$tmp}");
        }
        if (!@rename($tmp, $link)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot move symlink {$tmp} over {$link}");
        }
    }

    /**
     * Creates a relative symlink from $link to the absolute path $target (LAY-01).
     */
    public function linkRelative(string $link, string $target): void
    {
        $this->swapSymlink($link, self::relativePath(dirname($link), $target));
    }

    /**
     * Relative path from folder $fromDir to $to. Both must be absolute. Works
     * lexically, so neither needs to exist.
     */
    public static function relativePath(string $fromDir, string $to): string
    {
        $from = explode('/', trim(self::normalize($fromDir), '/'));
        $target = explode('/', trim(self::normalize($to), '/'));
        if ($from === ['']) {
            $from = [];
        }
        if ($target === ['']) {
            $target = [];
        }

        $common = 0;
        $max = min(count($from), count($target));
        while ($common < $max && $from[$common] === $target[$common]) {
            $common++;
        }

        $parts = array_merge(
            array_fill(0, count($from) - $common, '..'),
            array_slice($target, $common),
        );

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /**
     * Lexical normalisation of an absolute path: removes ".", "..", duplicate slashes.
     */
    public static function normalize(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException("Not an absolute path: {$path}");
        }
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }

        return '/' . implode('/', $out);
    }

    public static function isInside(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = rtrim(self::normalize($root), '/');

        return str_starts_with($path, $root . '/');
    }

    /**
     * FS-03 (CRITICAL): delete a tree without ever following a symlink.
     *
     * Allowed only strictly inside tmp/, tools/ or a site's releases/ folder
     * (in its site_dir, LAY-04), or a site's bare mirror (repo.git,
     * repo.git.broken-<ts>), and never the target of a site's `current` link.
     * A symlink at $path itself is unlinked, not descended into.
     */
    public function deleteTree(string $path): void
    {
        $this->assertDeletable($path);
        $path = self::normalize($path);
        if (!is_link($path) && !file_exists($path)) {
            return;
        }
        $this->deleteNoFollow($path);
    }

    /**
     * Removes a whole site folder, sites/<name>, without following symlinks.
     * Only for undoing a Create that failed (WIZ-04) and, later, removing a
     * site; FS-03's deleteTree() never allows it. The path must be exactly
     * sites/<name> and must not be a symlink.
     */
    public function deleteSiteFolder(string $site): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $site) !== 1) {
            throw new RuntimeException("Refusing to delete the site folder of '{$site}': not a site name");
        }
        $dir = self::normalize($this->paths->sitesDir() . '/' . $site);
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to delete {$dir}: it is a symlink");
        }
        if (!is_dir($dir)) {
            return;
        }
        $this->deleteNoFollow($dir);
    }

    /**
     * FS-03 for one release: $dir must be exactly <site folder>/releases/<id>,
     * with no symlink above it, and never the target of the site's `current` link.
     */
    public function deleteRelease(string $site, string $dir): void
    {
        $normal = self::normalize($dir);
        $releases = self::normalize($this->paths->releasesDir($site));
        if (dirname($normal) !== $releases || in_array(basename($normal), ['', '.', '..'], true)) {
            throw new RuntimeException("Refusing to delete {$dir}: not a release of {$site}");
        }
        $this->assertNoSymlinkAbove($normal, self::normalize($this->paths->siteFilesDir($site)));
        $this->assertNotLive($site, $normal, $dir);
        if (!is_link($normal) && !file_exists($normal)) {
            return;
        }
        $this->deleteNoFollow($normal);
    }

    /**
     * Removes a site's own folder (site_dir: current, releases, shared) when the
     * site is removed or its Create failed. It must be inside the home folder and
     * outside ~/cpdeploy, not a symlink, and hold nothing but current, releases
     * and shared — so a hand-edited site_dir can never point it at other files.
     */
    public function deleteSiteFiles(string $site): void
    {
        $dir = self::normalize($this->paths->siteFilesDir($site));
        $home = self::normalize($this->paths->home());
        $root = self::normalize($this->paths->root());
        if (!self::isInside($dir, $home) || $dir === $root || self::isInside($dir, $root) || self::isInside($root, $dir)) {
            throw new RuntimeException("Refusing to delete {$dir}: it must be a folder in your home folder, outside ~/cpdeploy");
        }
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to delete {$dir}: it is a symlink");
        }
        if (!is_dir($dir)) {
            return;
        }
        $this->assertNoSymlinkAbove($dir, $home);
        $other = array_diff(scandir($dir) ?: [], ['.', '..', 'current', 'releases', 'shared']);
        if ($other !== []) {
            throw new RuntimeException("Refusing to delete {$dir}: it holds files cpdeploy didn't put there (" . implode(', ', array_slice($other, 0, 5)) . ')');
        }
        $this->deleteNoFollow($dir);
    }

    /**
     * Throws unless deleteTree() may remove $path.
     */
    public function assertDeletable(string $path): void
    {
        $normal = self::normalize($path);
        $allowed = false;
        foreach ($this->paths->deletableRoots() as $root) {
            if (self::isInside($normal, $root)) {
                $allowed = true;
                break;
            }
        }
        // <site_dir>/releases/<id>[/…] of a known site.
        $releaseSite = null;
        $base = self::normalize($this->paths->root());
        if (!$allowed) {
            foreach ($this->paths->siteNames() as $site) {
                try {
                    $files = self::normalize($this->paths->siteFilesDir($site));
                } catch (RuntimeException) {
                    continue;
                }
                if (self::isInside($normal, $files . '/releases')) {
                    $allowed = true;
                    $releaseSite = $site;
                    $base = $files;
                    break;
                }
            }
        }
        $sitesDir = self::normalize($this->paths->sitesDir());
        if (!$allowed && self::isInside($normal, $sitesDir)) {
            $relative = substr($normal, strlen($sitesDir) + 1);
            $parts = explode('/', $relative);
            // sites/<site>/repo.git and repo.git.broken-<ts>: the bare mirror (GIT-16 repair).
            if (count($parts) === 2 && preg_match('/^repo\.git(\.broken-[0-9-]+)?$/', $parts[1]) === 1) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            throw new RuntimeException("Refusing to delete {$path}: outside the folders cpdeploy may delete in");
        }

        $this->assertNoSymlinkAbove($normal, $base);

        if ($releaseSite !== null) {
            $this->assertNotLive($releaseSite, $normal, $path);
        }
    }

    private function assertNotLive(string $site, string $normal, string $path): void
    {
        $current = $this->paths->current($site);
        $live = is_link($current) ? realpath($current) : false;
        $real = is_link($normal) ? false : realpath($normal);
        if ($live !== false && $real !== false && ($real === $live || self::isInside($live, $real))) {
            throw new RuntimeException("Refusing to delete {$path}: it is the live release");
        }
    }

    /**
     * No folder between $base and $path may be a symlink: a path that reaches
     * shared/ through a release's link must never be deleted.
     */
    private function assertNoSymlinkAbove(string $path, string $base): void
    {
        $parent = dirname($path);
        $realBase = realpath($base);
        $realParent = realpath($parent);
        if ($realBase !== false && $realParent !== false
            && $realParent !== $realBase . substr($parent, strlen($base))) {
            throw new RuntimeException("Refusing to delete {$path}: a folder above it is a symlink");
        }
    }

    private function deleteNoFollow(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path) && (is_link($path) || file_exists($path))) {
                throw new RuntimeException("Cannot delete {$path}");
            }

            return;
        }

        // Folders we own may have been exported read-only; make them writable first.
        @chmod($path, 0700);
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException("Cannot read folder {$path}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteNoFollow($path . '/' . $entry);
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("Cannot remove folder {$path}");
        }
    }

    /**
     * Creates a folder (and parents) and applies $mode to the leaf.
     */
    public function ensureDir(string $dir, int $mode = Paths::MODE_PUBLIC_DIR): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create folder {$dir}");
        }
        @chmod($dir, $mode);
    }

    /**
     * FS-04: `cp -a`, preserving symlinks as symlinks.
     */
    public function copyTree(string $from, string $to, float $timeout = 600.0): void
    {
        $result = $this->shell->run(['cp', '-a', '--', $from, $to], new RunOptions(timeout: $timeout, label: 'copy'));
        if (!$result->successful()) {
            throw new RuntimeException("Cannot copy {$from} to {$to}: " . trim($result->stderr));
        }
    }

    /**
     * FS-05: disk usage in KB via `du -sk`, or null when it can't be measured.
     */
    public function diskUsageKb(string $path, float $timeout = 120.0): ?int
    {
        $result = $this->shell->run(['du', '-sk', '--', $path], new RunOptions(timeout: $timeout, label: 'du'));
        if (!$result->successful() || preg_match('/^(\d+)/', $result->stdout, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Temp file under ~/cpdeploy/tmp with the cpd- prefix and mode 600 (SEC-08).
     */
    public function tempFile(string $purpose, string $contents = ''): string
    {
        $this->ensureDir($this->paths->tmpDir(), Paths::MODE_PRIVATE_DIR);
        $file = $this->paths->tmpDir() . '/cpd-' . $purpose . '-' . bin2hex(random_bytes(8));
        $this->writeAtomic($file, $contents, Paths::MODE_SECRET_FILE);

        return $file;
    }

    public function tempDir(string $purpose): string
    {
        $this->ensureDir($this->paths->tmpDir(), Paths::MODE_PRIVATE_DIR);
        $dir = $this->paths->tmpDir() . '/cpd-' . $purpose . '-' . bin2hex(random_bytes(8));
        $this->ensureDir($dir, Paths::MODE_PRIVATE_DIR);

        return $dir;
    }

    /**
     * LAY-03: remove tmp/ entries older than $maxAge seconds that the tool created.
     */
    public function cleanTmp(int $maxAge = 86400): int
    {
        $dir = $this->paths->tmpDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_starts_with($entry, 'cpd-')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            $stat = @lstat($path);
            if ($stat === false || (time() - $stat['mtime']) < $maxAge) {
                continue;
            }
            try {
                $this->deleteTree($path);
                $removed++;
            } catch (RuntimeException) {
                // Best effort: leave it for next time.
            }
        }

        return $removed;
    }
}
