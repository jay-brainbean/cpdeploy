<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\LineDiff;
use PHPUnit\Framework\TestCase;

final class LineDiffTest extends TestCase
{
    /**
     * @covers-req DOC-05
     */
    public function testChangesWithContext(): void
    {
        $old = "a\nb\nc\nd\ne\nf\ng\n";
        $new = "a\nb\nc\nX\ne\nf\ng\nh\n";

        self::assertSame(['  b', '  c', '- d', '+ X', '  e', '  f', '  g', '+ h'], LineDiff::lines($old, $new));
        self::assertSame(['- d', '+ X', '…', '+ h'], LineDiff::lines($old, $new, 0));
    }

    public function testIdenticalAndEmpty(): void
    {
        self::assertSame([], LineDiff::lines("same\r\n", "same\n"));
        self::assertSame(['+ only'], LineDiff::lines('', "only\n"));
        self::assertSame(['- gone'], LineDiff::lines("gone\n", ''));
    }

    public function testTooLarge(): void
    {
        self::assertNull(LineDiff::lines(str_repeat("x\n", LineDiff::MAX_LINES + 1), ''));
    }
}
