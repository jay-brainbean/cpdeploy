<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Docroot;

use Cpdeploy\Docroot\HandlerBlock;
use Cpdeploy\Docroot\LaravelRewrites;
use PHPUnit\Framework\TestCase;

final class LaravelRewritesTest extends TestCase
{
    private const HANDLER = HandlerBlock::BEGIN . "\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n" . HandlerBlock::END;

    public function testNeededOnlyWithoutRulesOfItsOwn(): void
    {
        self::assertTrue(LaravelRewrites::needed(null));
        self::assertTrue(LaravelRewrites::needed(''));
        self::assertTrue(LaravelRewrites::needed("# just a comment\n"));
        self::assertTrue(LaravelRewrites::needed(self::HANDLER . "\n"));
        self::assertFalse(LaravelRewrites::needed("RewriteEngine On\n"));
        self::assertFalse(LaravelRewrites::needed(LaravelRewrites::add(null)), 'Added once, not again');
    }

    public function testAddKeepsTheHandlerBlockOnTop(): void
    {
        $htaccess = LaravelRewrites::add(self::HANDLER . "\n");

        self::assertStringStartsWith(self::HANDLER . "\n\n" . LaravelRewrites::BEGIN, $htaccess);
        self::assertSame(self::HANDLER, HandlerBlock::capture($htaccess));
        self::assertTrue(LaravelRewrites::routesToIndex($htaccess));
        self::assertStringContainsString("\n    RewriteEngine On\n", $htaccess);
    }

    public function testStripRemovesOnlyTheAddedBlock(): void
    {
        self::assertSame(self::HANDLER . "\n\n", LaravelRewrites::strip(LaravelRewrites::add(self::HANDLER)));
        self::assertSame('', LaravelRewrites::strip(LaravelRewrites::add(null)));
        self::assertSame("Redirect 301 /a /b\n", LaravelRewrites::strip("Redirect 301 /a /b\n"));
    }

    public function testRoutesToIndex(): void
    {
        self::assertTrue(LaravelRewrites::routesToIndex("RewriteEngine On\n    RewriteRule ^ index.php [L]\n"));
        self::assertTrue(LaravelRewrites::routesToIndex("RewriteRule ^(.*)$ /index.php/$1 [L]\n"));
        self::assertTrue(LaravelRewrites::routesToIndex("FallbackResource /index.php\n"));
        self::assertFalse(LaravelRewrites::routesToIndex("# RewriteRule ^ index.php [L]\n"));
        self::assertFalse(LaravelRewrites::routesToIndex(self::HANDLER));
        self::assertFalse(LaravelRewrites::routesToIndex(''));
    }
}
