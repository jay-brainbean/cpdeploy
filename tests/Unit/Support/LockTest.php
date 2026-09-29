<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Lock;
use Cpdeploy\Tests\Support\TestCase;

final class LockTest extends TestCase
{
    /**
     * @covers-req LCK-01
     */
    public function testSecondHolderGetsELockedWithDetails(): void
    {
        $file = $this->tmp . '/.lock';
        $clock = new Clock(new \DateTimeImmutable('2026-09-29T03:02:00Z'));
        $first = Lock::site($file, 'shop', 'deploy', 'tester', '1.0.0', $clock);

        try {
            Lock::site($file, 'shop', 'rollback', 'tester', '1.0.0', $clock);
            self::fail('Second lock was granted');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::LOCKED, $e->errorCode);
            self::assertSame(10, $e->exitCode());
            self::assertStringContainsString('(deploy, started ', $e->getMessage());
            self::assertStringContainsString('by PID ' . getmypid(), $e->getMessage());
            self::assertStringContainsString('running for shop', $e->getMessage());
        }

        self::assertTrue(Lock::isHeld($file));
        self::assertSame(0600, fileperms($file) & 0777);
        $holder = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($holder);
        self::assertSame('deploy', $holder['action']);
        self::assertSame('2026-09-29T03:02:00Z', $holder['started_at']);

        $first->release();
        self::assertFalse(Lock::isHeld($file));
        Lock::site($file, 'shop', 'rollback', 'tester', '1.0.0', $clock)->release();
    }

    /**
     * The kernel drops a flock when its holder dies.
     *
     * @covers-req LCK-01
     */
    public function testLockHeldByAnotherProcessIsReleasedWhenItExits(): void
    {
        $file = $this->tmp . '/.lock';
        $proc = proc_open(['flock', '-x', $file, 'sleep', '0.5'], [], $pipes);
        self::assertIsResource($proc);
        usleep(200_000);
        self::assertTrue(Lock::isHeld($file));
        proc_close($proc);
        self::assertFalse(Lock::isHeld($file));
    }

    /**
     * @covers-req LCK-02
     */
    public function testBlockingLockGivesUpAfterTheDeadline(): void
    {
        $file = $this->tmp . '/tools.lock';
        $held = Lock::blocking($file);

        try {
            Lock::blocking($file, 0.3);
            self::fail('Granted twice');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::LOCKED, $e->errorCode);
        }
        $held->release();
        Lock::blocking($file, 0.3)->release();
    }
}
