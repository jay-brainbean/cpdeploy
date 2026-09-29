<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Fs;
use Cpdeploy\Tests\Support\TestCase;
use RuntimeException;

final class FsTest extends TestCase
{
    private Fs $fs;
    private Paths $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $services = $this->services();
        $this->fs = $services->fs();
        $this->paths = $services->paths();
        foreach ($this->paths->skeleton() as $dir => $mode) {
            $this->fs->ensureDir($dir, $mode);
        }
    }

    /**
     * The critical test: deleting a release must never follow its links into shared/.
     *
     * @covers-req FS-03
     * @covers-req INV-06
     */
    public function testSafeDeleteNeverFollowsSymlinks(): void
    {
        $shared = $this->paths->sharedDir('shop');
        $this->fs->ensureDir($shared . '/storage/app', 0711);
        file_put_contents($shared . '/storage/app/sentinel.txt', 'keep me');
        file_put_contents($shared . '/.env', 'APP_KEY=secret');

        $release = $this->paths->release('shop', '20260929-030512');
        $this->fs->ensureDir($release . '/storage', 0755);
        $this->fs->ensureDir($release . '/public/build', 0755);
        file_put_contents($release . '/public/build/app.js', 'x');
        $this->fs->linkRelative($release . '/storage/app', $shared . '/storage/app');
        $this->fs->linkRelative($release . '/.env', $shared . '/.env');
        // A link to a folder outside the tool root entirely.
        mkdir($this->tmp . '/outside');
        file_put_contents($this->tmp . '/outside/sentinel.txt', 'keep me too');
        symlink($this->tmp . '/outside', $release . '/public/outside');

        $this->fs->deleteTree($release);

        self::assertDirectoryDoesNotExist($release);
        self::assertFileExists($shared . '/storage/app/sentinel.txt');
        self::assertFileExists($shared . '/.env');
        self::assertFileExists($this->tmp . '/outside/sentinel.txt');
    }

    /**
     * @covers-req FS-03
     */
    public function testSafeDeleteUnlinksASymlinkPathInsteadOfDescending(): void
    {
        $target = $this->paths->sharedDir('shop') . '/data';
        $this->fs->ensureDir($target, 0711);
        file_put_contents($target . '/sentinel.txt', 'keep');
        $link = $this->paths->tmpDir() . '/cpd-link';
        symlink($target, $link);

        $this->fs->deleteTree($link);

        self::assertFalse(is_link($link));
        self::assertFileExists($target . '/sentinel.txt');
    }

    /**
     * @covers-req FS-03
     * @covers-req INV-02
     */
    public function testSafeDeleteRefusesPathsOutsideAllowedRoots(): void
    {
        $shared = $this->paths->sharedDir('shop');
        $this->fs->ensureDir($shared, 0711);

        foreach ([$shared, $this->paths->backupsDir('shop'), $this->paths->releasesDir('shop'), $this->home, $this->paths->root(), $this->paths->tmpDir()] as $path) {
            try {
                $this->fs->deleteTree($path);
                self::fail("deleteTree() accepted {$path}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('Refusing', $e->getMessage());
            }
        }
        self::assertDirectoryExists($shared);
    }

    /**
     * @covers-req FS-03
     */
    public function testSafeDeleteRefusesDotDotEscapes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->fs->deleteTree($this->paths->releasesDir('shop') . '/x/../../shared');
    }

    /**
     * @covers-req FS-03
     * @covers-req INV-02
     */
    public function testSafeDeleteRefusesTheLiveRelease(): void
    {
        $release = $this->paths->release('shop', '20260929-030512');
        $this->fs->ensureDir($release, 0755);
        $this->fs->linkRelative($this->paths->current('shop'), $release);

        $this->expectExceptionMessage('live release');
        $this->fs->deleteTree($release);
    }

    /**
     * @covers-req FS-03
     */
    public function testSafeDeleteRefusesPathsReachedThroughASymlinkedFolder(): void
    {
        $shared = $this->paths->sharedDir('shop') . '/storage';
        $this->fs->ensureDir($shared . '/app', 0755);
        file_put_contents($shared . '/app/sentinel.txt', 'keep');
        $release = $this->paths->release('shop', 'r1');
        $this->fs->ensureDir($release, 0755);
        $this->fs->linkRelative($release . '/storage', $shared);

        try {
            $this->fs->deleteTree($release . '/storage/app');
            self::fail('Deleted through a symlinked folder');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }
        self::assertFileExists($shared . '/app/sentinel.txt');
    }

    /**
     * @covers-req FS-01
     * @covers-req FS-06
     */
    public function testAtomicWriteCreatesFileWithModeAndNoTempLeftovers(): void
    {
        $file = $this->paths->root() . '/config.yml';
        $this->fs->writeAtomic($file, "a: 1\n", 0600);
        $this->fs->writeAtomic($file, "a: 2\n", 0600);

        self::assertSame("a: 2\n", file_get_contents($file));
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($file . '.cpd-tmp-*'));

        $public = $this->paths->root() . '/public.txt';
        $this->fs->writeAtomic($public, 'x', 0644);
        self::assertSame(0644, fileperms($public) & 0777);
    }

    /**
     * @covers-req FS-02
     * @covers-req LAY-01
     */
    public function testSwapSymlinkReplacesTheLinkAtomicallyWithARelativeTarget(): void
    {
        $a = $this->paths->release('shop', 'a');
        $b = $this->paths->release('shop', 'b');
        $this->fs->ensureDir($a);
        $this->fs->ensureDir($b);
        $current = $this->paths->current('shop');

        $this->fs->linkRelative($current, $a);
        self::assertSame('releases/a', readlink($current));
        $this->fs->linkRelative($current, $b);
        self::assertSame('releases/b', readlink($current));
        self::assertSame(realpath($b), realpath($current));
        self::assertSame([], glob($current . '.cpd-tmp-*'));
    }

    /**
     * @covers-req FS-02
     */
    public function testSwapSymlinkRefusesToReplaceARealFolder(): void
    {
        $dir = $this->paths->siteDir('shop') . '/current';
        $this->fs->ensureDir($dir);

        $this->expectException(RuntimeException::class);
        $this->fs->swapSymlink($dir, 'releases/a');
    }

    /**
     * @covers-req LAY-01
     */
    public function testRelativePath(): void
    {
        self::assertSame('releases/20260929-030512', Fs::relativePath('/h/cpdeploy/sites/shop', '/h/cpdeploy/sites/shop/releases/20260929-030512'));
        self::assertSame('cpdeploy/sites/shop/current/public', Fs::relativePath('/home/u', '/home/u/cpdeploy/sites/shop/current/public'));
        self::assertSame('../../../../shared/storage/app', Fs::relativePath('/s/releases/r1/storage/x', '/s/shared/storage/app'));
        self::assertSame('../storage/app/public', Fs::relativePath('/s/releases/r1/public', '/s/releases/r1/storage/app/public'));
        self::assertSame('.', Fs::relativePath('/a/b', '/a/b'));
        self::assertSame('..', Fs::relativePath('/a/b', '/a'));
    }

    public function testNormalizeAndIsInside(): void
    {
        self::assertSame('/a/c', Fs::normalize('/a/./b/../c//'));
        self::assertTrue(Fs::isInside('/a/b/c', '/a/b'));
        self::assertFalse(Fs::isInside('/a/b', '/a/b'));
        self::assertFalse(Fs::isInside('/a/bc', '/a/b'));
        self::assertFalse(Fs::isInside('/a/b/../../etc', '/a/b'));
    }

    /**
     * @covers-req LAY-03
     * @covers-req SEC-08
     */
    public function testCleanTmpRemovesOnlyOldEntriesTheToolCreated(): void
    {
        $tmp = $this->paths->tmpDir();
        $old = $this->fs->tempDir('old');
        file_put_contents($old . '/f', 'x');
        $fresh = $this->fs->tempFile('fresh', 'x');
        file_put_contents($tmp . '/user-file', 'x');
        touch($old, time() - 90000);
        touch($tmp . '/user-file', time() - 90000);

        self::assertSame(0600, fileperms($fresh) & 0777);
        self::assertSame(1, $this->fs->cleanTmp());
        self::assertDirectoryDoesNotExist($old);
        self::assertFileExists($fresh);
        self::assertFileExists($tmp . '/user-file');
    }

    /**
     * @covers-req FS-04
     * @covers-req FS-05
     */
    public function testCopyTreeKeepsSymlinksAndDiskUsage(): void
    {
        $src = $this->fs->tempDir('src');
        mkdir($src . '/vendor');
        file_put_contents($src . '/vendor/a.php', str_repeat('x', 5000));
        symlink('a.php', $src . '/vendor/link.php');
        $dst = $this->paths->tmpDir() . '/cpd-dst';

        $this->fs->copyTree($src . '/vendor', $dst);

        self::assertTrue(is_link($dst . '/link.php'));
        self::assertSame('a.php', readlink($dst . '/link.php'));
        self::assertNotSame(fileinode($src . '/vendor/a.php'), fileinode($dst . '/a.php'));
        self::assertGreaterThan(0, $this->fs->diskUsageKb($dst));
    }

    /**
     * WIZ-04 undo: exactly sites/<name>, links inside are removed, not followed.
     */
    public function testDeleteSiteFolder(): void
    {
        $site = $this->paths->siteDir('shop');
        $this->fs->ensureDir($site . '/shared', 0711);
        mkdir($this->tmp . '/outside');
        file_put_contents($this->tmp . '/outside/sentinel.txt', 'keep me');
        symlink($this->tmp . '/outside', $site . '/shared/outside');

        $this->fs->deleteSiteFolder('shop');

        self::assertDirectoryDoesNotExist($site);
        self::assertFileExists($this->tmp . '/outside/sentinel.txt');
        $this->fs->deleteSiteFolder('shop'); // already gone: nothing to do

        symlink($this->tmp . '/outside', $this->paths->siteDir('linked'));
        try {
            $this->fs->deleteSiteFolder('linked');
            self::fail('a symlinked site folder must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('it is a symlink', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->fs->deleteSiteFolder('../tmp');
    }
}
