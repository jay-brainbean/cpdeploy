<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Runtime;

use Cpdeploy\Runtime\ComposerInstaller;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\LocalServer;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\MemoryReporter;

final class ComposerInstallerTest extends TestCase
{
    private LocalServer $mirror;
    private string $www;

    protected function setUp(): void
    {
        parent::setUp();
        $this->www = $this->tmp . '/mirror';
        $this->publish('latest-2.x', '<?php echo "composer 2.8.12";');
        $this->publish('2.8.4', '<?php echo "composer 2.8.4";');
        $this->mirror = new LocalServer($this->www);
        mkdir($this->root, 0711, true);
        file_put_contents($this->root . '/config.yml', "schema: 1\nmirrors:\n  composer: " . $this->mirror->url() . "\n");
    }

    protected function tearDown(): void
    {
        $this->mirror->stop();
        parent::tearDown();
    }

    private function publish(string $channel, string $phar, ?string $sha = null): void
    {
        $dir = $this->www . '/' . $channel;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/composer.phar', $phar);
        file_put_contents($dir . '/composer.phar.sha256', ($sha ?? hash('sha256', $phar)) . "  composer.phar\n");
    }

    /**
     * @covers-req CMP-01
     */
    public function testChannelMapping(): void
    {
        self::assertSame('latest-2.x', ComposerInstaller::channel('2'));
        self::assertSame('latest-stable', ComposerInstaller::channel('stable'));
        self::assertSame('latest-2.2.x', ComposerInstaller::channel('2.2'));
        self::assertSame('latest-2.2.x', ComposerInstaller::channel('lts'));
        self::assertSame('2.8.4', ComposerInstaller::channel('2.8.4'));

        $this->expectException(CpdeployException::class);
        ComposerInstaller::channel('3.x');
    }

    /**
     * @covers-req CMP-01
     * @covers-req SEC-06
     */
    public function testDownloadsVerifiesAndCaches(): void
    {
        $installer = $this->services()->composerInstaller();
        $reporter = new MemoryReporter();

        $path = $installer->ensure('2', $reporter);

        self::assertSame($this->root . '/tools/composer/composer-latest-2.x.phar', $path);
        self::assertSame('<?php echo "composer 2.8.12";', file_get_contents($path));
        self::assertSame(0644, fileperms($path) & 0777);
        self::assertSame(['Downloading Composer (latest-2.x)'], $reporter->of('start'));

        // Cached: the mirror is no longer needed.
        unlink($this->www . '/latest-2.x/composer.phar');
        self::assertSame($path, $this->services()->composerInstaller()->ensure('2'));
    }

    /**
     * S-12: a checksum mismatch on the first download caches nothing.
     *
     * @covers-req CMP-01
     * @covers-req SEC-06
     */
    public function testChecksumMismatchCachesNothing(): void
    {
        $this->publish('latest-2.x', 'tampered', str_repeat('a', 64));

        try {
            $this->services()->composerInstaller()->ensure('2', null, 4);
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CHECKSUM, $e->errorCode);
            self::assertSame(4, $e->exitCode());
            self::assertStringContainsString('failed its checksum — not used', $e->getMessage());
        }
        self::assertSame([], glob($this->root . '/tools/composer/*') ?: []);
        self::assertSame([], glob($this->root . '/tmp/cpd-composer-*') ?: []);
    }

    /**
     * @covers-req CMP-01
     */
    public function testStaleChannelRefreshesButExactVersionsNever(): void
    {
        $installer = $this->services()->composerInstaller();
        $channel = $installer->ensure('2');
        $exact = $installer->ensure('2.8.4');
        touch($channel, time() - 31 * 86400);
        touch($exact, time() - 400 * 86400);
        $this->publish('latest-2.x', '<?php echo "composer 2.9.0";');
        $this->publish('2.8.4', '<?php echo "changed";');

        $installer->ensure('2');
        $installer->ensure('2.8.4');

        self::assertSame('<?php echo "composer 2.9.0";', file_get_contents($channel));
        self::assertSame('<?php echo "composer 2.8.4";', file_get_contents($exact));
    }

    public function testFailedRefreshKeepsTheVerifiedCopy(): void
    {
        $channel = $this->services()->composerInstaller()->ensure('2');
        touch($channel, time() - 31 * 86400);
        $this->mirror->stop();
        $reporter = new MemoryReporter();

        self::assertSame($channel, $this->services()->composerInstaller()->ensure('2', $reporter));
        self::assertStringContainsString("Couldn't refresh Composer", $reporter->of('warn')[0]);
    }

    public function testDownloadFailureWithoutCacheIsEDownload(): void
    {
        try {
            $this->services()->composerInstaller()->ensure('stable');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::DOWNLOAD, $e->errorCode);
            self::assertStringContainsString('/latest-stable/composer.phar.sha256', $e->getMessage());
            self::assertStringContainsString('HTTP 404', $e->getMessage());
        }
    }
}
