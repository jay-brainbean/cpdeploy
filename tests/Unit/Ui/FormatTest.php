<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use Cpdeploy\Ui\Format;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    /**
     * @covers-req UI-06
     */
    public function testDurations(): void
    {
        self::assertSame('0s', Format::duration(0.2));
        self::assertSame('38s', Format::duration(38));
        self::assertSame('1m 41s', Format::duration(101));
        self::assertSame('1h 2m', Format::duration(3720));
    }

    /**
     * @covers-req UI-06
     */
    public function testSizesUseBase1024(): void
    {
        self::assertSame('512 B', Format::bytes(512));
        self::assertSame('1.0 KB', Format::bytes(1024));
        self::assertSame('12.3 MB', Format::bytes(12.3 * 1024 * 1024));
        self::assertSame('1.5 GB', Format::kilobytes(1572864));
    }

    /**
     * @covers-req UI-05
     */
    public function testRelativeTimes(): void
    {
        $now = new DateTimeImmutable('2026-09-29T12:00:00Z');
        self::assertSame('just now', Format::relative($now->modify('-10 seconds'), $now));
        self::assertSame('10m ago', Format::relative($now->modify('-10 minutes'), $now));
        self::assertSame('2h ago', Format::relative($now->modify('-2 hours'), $now));
        self::assertSame('3d ago', Format::relative($now->modify('-3 days'), $now));
        self::assertSame('in 4d', Format::relative($now->modify('+4 days'), $now));
    }

    /**
     * Labels must fit 74 columns on an 80×24 terminal (§5.3, UI-01).
     */
    public function testTruncateTo74Columns(): void
    {
        $long = str_repeat('é', 100);
        $cut = Format::truncate($long);

        self::assertSame(74, mb_strwidth($cut));
        self::assertStringEndsWith('…', $cut);
        self::assertSame('short', Format::truncate('short'));
        self::assertSame('abcd...', Format::truncate('abcdefghij', 7, '...'));
    }
}
