<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Docroot;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Docroot\HandlerBlock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

/**
 * @covers-req DOC-01
 */
final class DocrootManagerTest extends TestCase
{
    private DocrootManager $docroots;

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root . '/sites', 0711, true);
        $this->docroots = $this->services()->docroots();
    }

    private function config(string $name, string $domain, string $docroot, string $webDir = 'public'): SiteConfig
    {
        return new SiteConfig(SiteSchema::withDefaults([
            'name' => $name,
            'repo' => ['owner' => 'acme', 'name' => $name],
            'domain' => ['name' => $domain, 'docroot' => $docroot, 'web_dir' => $webDir],
            'php' => ['version' => '8.2'],
        ], (new Presets())->for('laravel')));
    }

    /**
     * @param array<string, string> $extra name => docroot
     * @return list<Domain>
     */
    private function domains(string $main, array $extra = []): array
    {
        $list = [new Domain('main.example.test', Domain::MAIN, $main, '192.0.2.10')];
        foreach ($extra as $name => $root) {
            $list[] = new Domain($name, Domain::ADDON, $root, '192.0.2.10');
        }

        return $list;
    }

    public function testASafeAddonDocroot(): void
    {
        $config = $this->config('shop', 'shop.example.test', $this->home . '/shop.example.test');
        $domains = $this->domains($this->home . '/public_html', ['shop.example.test' => $this->home . '/shop.example.test']);

        self::assertSame([], $this->docroots->problems($config, $domains));
    }

    public function testOtherDomainsInsidePublicHtml(): void
    {
        $config = $this->config('main', 'main.example.test', $this->home . '/public_html');
        $domains = $this->domains($this->home . '/public_html', ['blog.example.test' => $this->home . '/public_html/blog']);

        $problems = implode("\n", $this->docroots->problems($config, $domains));
        self::assertStringContainsString('contains the folders of other domains: blog.example.test', $problems);
    }

    public function testDocrootMovedInCpanelOrDomainGone(): void
    {
        $config = $this->config('shop', 'shop.example.test', $this->home . '/shop.example.test');

        $moved = $this->domains($this->home . '/public_html', ['shop.example.test' => $this->home . '/shop2']);
        self::assertStringContainsString('cpdeploy config shop set domain.docroot ' . $this->home . '/shop2', implode(' ', $this->docroots->problems($config, $moved)));

        $gone = $this->domains($this->home . '/public_html');
        self::assertStringContainsString('no longer exists', implode(' ', $this->docroots->problems($config, $gone)));
    }

    public function testForeignSymlinkAndDocrootInsideAnotherSite(): void
    {
        mkdir($this->home . '/elsewhere');
        symlink($this->home . '/elsewhere', $this->home . '/shop.example.test');
        $config = $this->config('shop', 'shop.example.test', $this->home . '/shop.example.test');
        $domains = $this->domains($this->home . '/public_html', ['shop.example.test' => $this->home . '/shop.example.test']);
        self::assertStringContainsString('did not create', implode(' ', $this->docroots->problems($config, $domains)));

        // (c): public_html is the "main" site's symlink; an addon folder under it lives inside that site.
        $main = $this->config('main', 'main.example.test', $this->home . '/public_html');
        $this->services()->sites()->save($main);
        mkdir($this->root . '/sites/main/releases/r1/public/addon', 0755, true);
        symlink('releases/r1', $this->root . '/sites/main/current');
        symlink('cpdeploy/sites/main/current/public', $this->home . '/public_html');
        $addon = $this->config('addon', 'addon.example.test', $this->home . '/public_html/addon');
        $problems = $this->docroots->problems($addon, $this->domains($this->home . '/public_html', ['addon.example.test' => $this->home . '/public_html/addon']));
        self::assertStringContainsString('lives inside another managed site (main)', implode(' ', $problems));

        $this->expectException(CpdeployException::class);
        $this->docroots->assertSafe($addon, []);
    }

    /**
     * @covers-req DOC-02
     * @covers-req DOC-04
     * @covers-req DOC-08
     * @covers-req LAY-01
     * @covers-req SEC-12
     */
    public function testConvertAFolderAndRepoint(): void
    {
        $docroot = $this->home . '/shop.example.test';
        mkdir($docroot . '/.well-known/acme-challenge', 0755, true);
        file_put_contents($docroot . '/.well-known/acme-challenge/token', 't');
        file_put_contents($docroot . '/.user.ini', 'memory_limit=256M');
        file_put_contents($docroot . '/.htaccess', HandlerBlock::BEGIN . "\nAddHandler application/x-httpd-ea-php82 .php .php8\n" . HandlerBlock::END . "\n\nRedirect 301 /a /b\n");
        file_put_contents($docroot . '/index.html', 'old site');
        $config = $this->config('shop', 'shop.example.test', $docroot);

        $notices = [];
        $backup = $this->docroots->convert($config, '20260929-120000', $notices);

        self::assertSame('backups/docroot-20260929-120000', $backup);
        self::assertTrue(is_link($docroot));
        self::assertSame('cpdeploy/sites/shop/current/public', readlink($docroot));
        self::assertSame('old site', file_get_contents($this->root . '/sites/shop/' . $backup . '/index.html'));
        self::assertSame(0700, fileperms($this->root . '/sites/shop/backups') & 0777);
        self::assertSame('t', file_get_contents($this->root . '/sites/shop/shared/docroot/.well-known/acme-challenge/token'));
        self::assertSame('memory_limit=256M', file_get_contents($this->root . '/sites/shop/shared/docroot/.user.ini'));
        self::assertStringContainsString('x-httpd-ea-php82', (string) file_get_contents($this->root . '/sites/shop/shared/php-handler.block'));
        self::assertCount(1, $notices);
        self::assertTrue($this->docroots->isConverted($config));
        self::assertFalse($this->docroots->needsRepoint($config));

        $changed = $config->with('domain.web_dir', 'web');
        self::assertTrue($this->docroots->needsRepoint($changed));
        $this->docroots->repoint($changed);
        self::assertSame('cpdeploy/sites/shop/current/web', readlink($docroot));

        $this->docroots->undoConversion($changed, $backup);
        self::assertFalse(is_link($docroot));
        self::assertSame('old site', file_get_contents($docroot . '/index.html'));
    }

    /**
     * @covers-req DOC-02
     * @covers-req DOC-03
     */
    public function testConvertAMissingDocrootAndEnsureHtaccess(): void
    {
        $config = $this->config('shop', 'shop.example.test', $this->home . '/sites/shop.example.test');
        $notices = [];

        self::assertNull($this->docroots->convert($config, '20260929-120000', $notices));
        self::assertSame('../cpdeploy/sites/shop/current/public', readlink($this->home . '/sites/shop.example.test'));

        mkdir($this->tmp . '/web');
        $this->docroots->ensureHtaccess($this->tmp . '/web');
        self::assertSame('', file_get_contents($this->tmp . '/web/.htaccess'));
    }

    public function testConvertRefusesAFile(): void
    {
        file_put_contents($this->home . '/shop.example.test', 'x');
        $config = $this->config('shop', 'shop.example.test', $this->home . '/shop.example.test');
        $notices = [];
        try {
            $this->docroots->convert($config, '20260929-120000', $notices);
            self::fail('Expected E_DOCROOT_UNSAFE');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::DOCROOT_UNSAFE, $e->errorCode);
        }
    }
}
