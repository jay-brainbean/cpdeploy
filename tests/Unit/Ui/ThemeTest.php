<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use Cpdeploy\Support\Environment;
use Cpdeploy\Ui\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    /**
     * @covers-req UI-04
     * @covers-req UI-07
     */
    public function testAsciiFallbackWithoutUtf8Locale(): void
    {
        self::assertTrue(Theme::detect(new Environment(['LANG' => 'en_US.UTF-8']))->unicode);
        self::assertTrue(Theme::detect(new Environment(['LC_ALL' => 'C.utf8']))->unicode);
        self::assertFalse(Theme::detect(new Environment(['LANG' => 'C']))->unicode);
        self::assertFalse(Theme::detect(new Environment([]))->unicode);
        self::assertFalse(Theme::detect(new Environment(['LANG' => 'en_US.UTF-8']), false)->unicode);

        $u = new Theme(true);
        $a = new Theme(false);
        $expected = ['ok' => ['✓', '[ok]'], 'fail' => ['✗', '[x]'], 'warn' => ['⚠', '[!]'], 'question' => ['?', '[?]'],
            'cursor' => ['❯', '>'], 'live' => ['●', '*'], 'other' => ['○', '-'], 'rolled_back' => ['↺', '<']];
        foreach ($expected as $name => [$uni, $ascii]) {
            self::assertSame($uni, $u->symbol($name));
            self::assertSame($ascii, $a->symbol($name));
        }
        self::assertSame('|', $a->spinnerFrame(0));
    }
}
