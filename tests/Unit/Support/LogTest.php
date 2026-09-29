<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Tests\Support\TestCase;

final class LogTest extends TestCase
{
    /**
     * @covers-req LOG-01
     */
    public function testLogHasHeaderFooterAndMode600(): void
    {
        $masker = new Masker();
        $masker->add('topsecretvalue');
        $clock = new Clock(new \DateTimeImmutable('2026-09-29T03:05:12Z'));
        $log = Log::open($this->tmp . '/logs', 'deploy', $masker, $clock, ['tool' => '1.0.0', 'site' => 'shop']);
        $log->write('using topsecretvalue');
        $log->close('success', 0);

        self::assertSame($this->tmp . '/logs/20260929-030512-deploy.log', $log->path);
        self::assertSame(0600, fileperms($log->path) & 0777);
        self::assertSame(0700, fileperms($this->tmp . '/logs') & 0777);
        $text = (string) file_get_contents($log->path);
        self::assertStringContainsString('site: shop', $text);
        self::assertStringContainsString('result: success, exit code 0', $text);
        self::assertStringNotContainsString('topsecretvalue', $text);

        $second = Log::open($this->tmp . '/logs', 'deploy', $masker, $clock, []);
        self::assertSame($this->tmp . '/logs/20260929-030512-deploy-2.log', $second->path);
    }

    /**
     * @covers-req LOG-02
     */
    public function testPruneKeepsTheNewest(): void
    {
        $dir = $this->tmp . '/logs';
        mkdir($dir);
        for ($i = 1; $i <= 55; $i++) {
            touch(sprintf('%s/20260901-%06d-deploy.log', $dir, $i));
        }

        self::assertSame(5, Log::prune($dir));
        $left = glob($dir . '/*.log') ?: [];
        self::assertCount(50, $left);
        self::assertFileDoesNotExist($dir . '/20260901-000005-deploy.log');
        self::assertFileExists($dir . '/20260901-000006-deploy.log');
    }

    /**
     * @covers-req LOG-03
     */
    public function testHistoryAppendAndRead(): void
    {
        $file = $this->tmp . '/history.jsonl';
        $masker = new Masker();
        $masker->add('hunter2hunter2');
        Log::appendHistory($file, ['ts' => '2026-09-29T03:06:55Z', 'action' => 'deploy', 'result' => 'success'], $masker);
        Log::appendHistory($file, ['action' => 'rollback', 'notes' => ['pw hunter2hunter2']], $masker);
        file_put_contents($file, "not json\n", FILE_APPEND);

        $entries = Log::readHistory($file);
        self::assertCount(2, $entries);
        self::assertSame('rollback', $entries[1]['action']);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertStringNotContainsString('hunter2hunter2', (string) file_get_contents($file));
        self::assertCount(1, Log::readHistory($file, 2));
    }
}
