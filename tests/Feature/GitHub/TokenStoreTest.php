<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\GitHub;

use Cpdeploy\GitHub\TokenStore;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Tests\Support\TestCase;

final class TokenStoreTest extends TestCase
{
    /**
     * @covers-req SEC-01
     * @covers-req SEC-14
     */
    public function testStoreReadAndRemove(): void
    {
        $services = $this->services();
        $store = $services->tokens();
        self::assertFalse($store->has());
        self::assertNull($store->get());

        $store->set("  github_pat_11AAAAAAA0123456789abc  \n");

        $file = $this->root . '/secrets/github-token';
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame(0700, fileperms($this->root . '/secrets') & 0777);
        chmod($file, 0644);
        self::assertSame('github_pat_11AAAAAAA0123456789abc', $store->get());
        self::assertSame(0600, fileperms($file) & 0777, 'Modes re-applied before reading');
        self::assertStringNotContainsString('github_pat_11AAAAAAA0123456789abc', $services->masker()->mask('x github_pat_11AAAAAAA0123456789abc'));

        self::assertTrue($store->remove());
        self::assertFileDoesNotExist($file);
        self::assertFalse($store->remove());
    }

    public function testRejectsThingsThatAreNotTokens(): void
    {
        self::assertTrue(TokenStore::looksValid('ghp_' . str_repeat('a', 36)));
        self::assertFalse(TokenStore::looksValid('short'));
        self::assertFalse(TokenStore::looksValid('has spaces in it and is long enough'));

        $this->expectException(CpdeployException::class);
        $this->services()->tokens()->set('Bearer abc');
    }
}
