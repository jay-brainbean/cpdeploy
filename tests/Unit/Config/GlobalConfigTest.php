<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Config;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Schema\GlobalSchema;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

final class GlobalConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root, 0711, true);
        $this->file = $this->root . '/config.yml';
    }

    public function testMissingFileMeansDefaults(): void
    {
        $config = GlobalConfig::load($this->file, $this->services()->fs());

        self::assertSame(GlobalSchema::defaults(), $config->toArray());
        self::assertSame(300, $config->timeout('git'));
        self::assertSame('https://nodejs.org/dist', $config->mirror('node'));
        self::assertSame('jay-brainbean/cpdeploy', $config->updateRepo());
        self::assertNull($config->uiFlag('unicode'));
    }

    /**
     * @covers-req CFG-01
     * @covers-req SEC-01
     */
    public function testSaveWritesHeaderAndMode600(): void
    {
        GlobalConfig::defaults()->save($this->file, $this->services()->fs());

        $text = (string) file_get_contents($this->file);
        self::assertStringStartsWith(GlobalConfig::HEADER . "\n", $text);
        self::assertSame(0600, fileperms($this->file) & 0777);
        self::assertStringNotContainsString('token', strtolower($text));
    }

    public function testPartialFileIsFilledWithDefaults(): void
    {
        file_put_contents($this->file, "schema: 1\ntimeouts:\n  git: 60\nui:\n  unicode: false\n");
        $config = GlobalConfig::load($this->file, $this->services()->fs());

        self::assertSame(60, $config->timeout('git'));
        self::assertSame(900, $config->timeout('composer'));
        self::assertFalse($config->uiFlag('unicode'));
    }

    /**
     * @covers-req CFG-03
     */
    public function testInvalidValuesListEveryProblem(): void
    {
        file_put_contents($this->file, "schema: 1\ndefaults:\n  keep_releases: 0\ntimeouts:\n  git: fast\nmirrors:\n  node: ftp://x\nui:\n  color: maybe\nupdate:\n  repo: nope\n");

        try {
            GlobalConfig::load($this->file, $this->services()->fs());
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CONFIG_INVALID, $e->errorCode);
            self::assertSame(2, $e->exitCode());
            foreach (['defaults.keep_releases', 'timeouts.git', 'mirrors.node', 'ui.color', 'update.repo'] as $key) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /**
     * @covers-req CFG-03
     */
    public function testUnknownKeysWarnAndAreKept(): void
    {
        file_put_contents($this->file, "schema: 1\ntimeoutz:\n  git: 5\nui:\n  colour: true\n");
        $fs = $this->services()->fs();
        $config = GlobalConfig::load($this->file, $fs);

        self::assertCount(2, $config->warnings);
        self::assertStringContainsString('timeoutz', implode("\n", $config->warnings));
        self::assertStringContainsString('ui.colour', implode("\n", $config->warnings));
        $config->save($this->file, $fs);
        self::assertStringContainsString('timeoutz', (string) file_get_contents($this->file));
    }

    /**
     * @covers-req CFG-02
     */
    public function testNewerSchemaIsRejected(): void
    {
        file_put_contents($this->file, 'schema: ' . (GlobalSchema::VERSION + 1) . "\n");

        $this->expectExceptionObject(new CpdeployException(ErrorCode::CONFIG_NEWER, $this->file . ' was written by a newer cpdeploy'));
        GlobalConfig::load($this->file, $this->services()->fs());
    }

    public function testBrokenYamlIsAClearError(): void
    {
        file_put_contents($this->file, "schema: 1\n  bad: [\n");

        $this->expectException(CpdeployException::class);
        GlobalConfig::load($this->file, $this->services()->fs());
    }
}
