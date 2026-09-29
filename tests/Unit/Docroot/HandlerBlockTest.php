<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Docroot;

use Cpdeploy\Docroot\HandlerBlock;
use PHPUnit\Framework\TestCase;

/**
 * @covers-req DOC-04
 */
final class HandlerBlockTest extends TestCase
{
    private const BLOCK = "# php -- BEGIN cPanel-generated handler, do not edit\n"
        . "# Set the “ea-php82” package as the default “PHP” programming language.\n"
        . "<IfModule mime_module>\n"
        . "  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n"
        . "</IfModule>\n"
        . '# php -- END cPanel-generated handler, do not edit';

    public function testCapture(): void
    {
        $htaccess = "RewriteEngine On\n\n" . self::BLOCK . "\n\n# after\n";

        self::assertSame(self::BLOCK, HandlerBlock::capture($htaccess));
        self::assertNull(HandlerBlock::capture("RewriteEngine On\n"));
    }

    public function testRewrite(): void
    {
        $alt = HandlerBlock::rewrite(self::BLOCK, 'alt', '8.3');
        self::assertStringContainsString('application/x-httpd-alt-php83 .php .php8 .phtml', $alt);
        self::assertStringContainsString('“alt-php83” package', $alt);

        $seven = HandlerBlock::rewrite(self::BLOCK, 'ea', '7.4');
        self::assertStringContainsString('x-httpd-ea-php74 .php .php7 .phtml', $seven);

        $ls = str_replace('ea-php82 ', 'ea-php82___lsphp ', self::BLOCK);
        self::assertStringContainsString('x-httpd-ea-php83___lsphp .php', HandlerBlock::rewrite($ls, 'ea', '8.3'));
    }

    public function testInjectIntoExistingMissingAndIsIdempotent(): void
    {
        $repo = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n</IfModule>\n";

        $once = HandlerBlock::inject($repo, self::BLOCK);
        self::assertSame(self::BLOCK . "\n\n" . $repo, $once);
        self::assertSame($once, HandlerBlock::inject($once, self::BLOCK));

        $other = HandlerBlock::rewrite(self::BLOCK, 'ea', '8.3');
        $twice = HandlerBlock::inject($once, $other);
        self::assertSame($other . "\n\n" . $repo, $twice);
        self::assertSame(1, substr_count($twice, HandlerBlock::BEGIN));

        self::assertSame(self::BLOCK . "\n\n", HandlerBlock::inject(null, self::BLOCK));
    }

    public function testStripAndOtherRules(): void
    {
        $htaccess = self::BLOCK . "\n\nRedirect 301 /old /new\n";
        self::assertSame("Redirect 301 /old /new\n", HandlerBlock::strip($htaccess));
        self::assertTrue(HandlerBlock::hasOtherRules($htaccess));
        self::assertFalse(HandlerBlock::hasOtherRules(self::BLOCK . "\n\n# just a comment\n"));
    }
}
