<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Signals;
use PHPUnit\Framework\TestCase;

/**
 * @covers-req LCK-04
 */
final class SignalsTest extends TestCase
{
    public function testResetForgetsACtrlC(): void
    {
        $signals = new Signals();
        $signals->request();
        self::assertTrue($signals->cancelRequested());
        self::assertNull($signals->terminating());

        $signals->reset();

        self::assertFalse($signals->cancelRequested(), 'The next operation must not start cancelled');
    }

    public function testHangUpAndSigtermAreKept(): void
    {
        foreach ([1, 15] as $signal) {
            $signals = new Signals();
            $signals->request($signal);
            $signals->reset();

            self::assertSame($signal, $signals->terminating());
            self::assertTrue($signals->cancelRequested());
        }
    }

    public function testTerminalLostCountsAsAHangUp(): void
    {
        $signals = new Signals();
        $signals->terminalLost();

        self::assertSame(1, $signals->terminating());
        self::assertTrue($signals->cancelRequested());
    }
}
