<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Deploy;

use Cpdeploy\Deploy\ReleaseManifest;
use Cpdeploy\Tests\Support\TestCase;

/**
 * DOC-06: the release manifest and the live-files drift it finds.
 */
final class ReleaseManifestTest extends TestCase
{
    private string $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->release = $this->root . '/sites/shop/releases/20260929-030512';
        foreach (['app', 'public/build', 'vendor/acme', 'storage/logs', 'bootstrap/cache'] as $dir) {
            mkdir($this->release . '/' . $dir, 0755, true);
        }
        file_put_contents($this->release . '/app/Kernel.php', '<?php // kernel');
        file_put_contents($this->release . '/public/index.php', '<?php // index');
        file_put_contents($this->release . '/public/.htaccess', 'RewriteEngine On');
        file_put_contents($this->release . '/public/build/app.js', 'js');
        file_put_contents($this->release . '/vendor/acme/lib.php', 'lib');
        file_put_contents($this->release . '/storage/logs/laravel.log', 'log');
        file_put_contents($this->release . '/bootstrap/cache/config.php', 'cache');
        file_put_contents($this->release . '/.release.json', '{}');
        file_put_contents($this->release . '/public/.cpd-release-' . str_repeat('a', 32) . '.txt', 'id');
        symlink('../shared/.env', $this->release . '/.env');
    }

    /**
     * @covers-req DOC-06
     */
    public function testManifestListsOnlyBuiltFiles(): void
    {
        $manifest = new ReleaseManifest($this->services()->fs());
        $manifest->write($this->release);

        $lines = file($this->release . '/' . ReleaseManifest::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $paths = array_map(static fn (string $l): string => substr($l, 42), $lines);
        self::assertSame(['app/Kernel.php', 'public/.htaccess', 'public/index.php'], $paths);
        self::assertSame(sha1('<?php // kernel') . '  app/Kernel.php', $lines[0]);
        self::assertSame(0644, fileperms($this->release . '/' . ReleaseManifest::FILE) & 0777);
    }

    /**
     * @covers-req DOC-06
     */
    public function testDriftFindsChangedAndAddedFiles(): void
    {
        $manifest = new ReleaseManifest($this->services()->fs());
        self::assertNull($manifest->drift($this->release), 'no manifest yet (a release built before M4)');
        $manifest->write($this->release);
        self::assertSame(['changed' => [], 'added' => []], $manifest->drift($this->release));

        file_put_contents($this->release . '/app/Kernel.php', '<?php // edited on the server');
        file_put_contents($this->release . '/app/New.php', '<?php');
        file_put_contents($this->release . '/public/.htaccess', 'Redirect 301 / /x');
        file_put_contents($this->release . '/vendor/acme/lib.php', 'patched');
        unlink($this->release . '/public/index.php');

        self::assertSame(
            ['changed' => ['app/Kernel.php'], 'added' => ['app/New.php']],
            $manifest->drift($this->release, ['public/.htaccess']),
        );
        self::assertNull($manifest->drift($this->release, [], -1.0), 'over the time budget: skipped');
    }
}
