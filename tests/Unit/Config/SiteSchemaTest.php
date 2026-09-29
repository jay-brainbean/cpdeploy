<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Config;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

final class SiteSchemaTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function site(array $over = [], string $type = 'laravel'): array
    {
        $data = [
            'name' => 'shop',
            'type' => $type,
            'repo' => ['owner' => 'acme', 'name' => 'shop'],
            'domain' => ['name' => 'shop.example.com', 'docroot' => '/home/u/shop.example.com'],
            'php' => ['version' => '8.2'],
        ];
        $merged = SiteSchema::withDefaults($data, (new Presets())->for($type));

        return array_replace_recursive($merged, $over);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function errors(array $data): array
    {
        return SiteSchema::validate($data)['errors'];
    }

    public function testAValidLaravelSite(): void
    {
        self::assertSame([], $this->errors($this->site()));
    }

    /**
     * @covers-req VAL-01
     */
    public function testName(): void
    {
        self::assertNotSame([], $this->errors($this->site(['name' => 'Shop'])));
        self::assertNotSame([], $this->errors($this->site(['name' => '-shop'])));
        self::assertNotSame([], $this->errors($this->site(['name' => str_repeat('a', 32)])));
        self::assertStringContainsString('reserved', implode(' ', $this->errors($this->site(['name' => 'deploy']))));
        self::assertSame([], $this->errors($this->site(['name' => 'shop-2'])));
    }

    /**
     * @covers-req VAL-02
     */
    public function testTypeAndStrategy(): void
    {
        self::assertNotSame([], $this->errors($this->site(['type' => 'wordpress'])));
        self::assertNotSame([], $this->errors($this->site(['strategy' => 'copy'])));
    }

    /**
     * @covers-req VAL-03
     * @covers-req VAL-04
     */
    public function testDocrootAndWebDir(): void
    {
        self::assertNotSame([], $this->errors($this->site(['domain' => ['docroot' => 'public_html']])));
        self::assertNotSame([], $this->errors($this->site(['domain' => ['web_dir' => '../public']])));
        self::assertNotSame([], $this->errors($this->site(['domain' => ['web_dir' => '/public']])));
        self::assertSame([], $this->errors($this->site(['domain' => ['web_dir' => 'public/app']])));
    }

    /**
     * @covers-req VAL-05
     * @covers-req VAL-06
     */
    public function testPhpAndNodeVersions(): void
    {
        self::assertNotSame([], $this->errors($this->site(['php' => ['version' => '8.2.1']])));
        self::assertNotSame([], $this->errors($this->site(['php' => ['family' => 'cl']])));
        self::assertNotSame([], $this->errors($this->site(['node' => ['version' => 'banana']])));
        foreach (['auto', 'none', '22', '22.20.0', '^20', 'lts/*', 'lts/jod'] as $ok) {
            self::assertSame([], $this->errors($this->site(['node' => ['version' => $ok]])), $ok);
        }
    }

    /**
     * @covers-req VAL-07
     */
    public function testStepValues(): void
    {
        self::assertNotSame([], $this->errors($this->site(['steps' => ['storage_link' => 'ask']])));
        self::assertNotSame([], $this->errors($this->site(['steps' => ['seed' => 'every']])));
        self::assertSame([], $this->errors($this->site(['steps' => ['seed' => 'first', 'migrate' => 'every']])));
    }

    /**
     * @covers-req VAL-08
     * @covers-req REL-02
     */
    public function testSharedPaths(): void
    {
        $e = implode(' ', $this->errors($this->site(['shared' => ['dirs' => ['storage/app', 'storage/framework/views']]])));
        self::assertStringContainsString('storage/framework/views', $e);
        self::assertNotSame([], $this->errors($this->site(['shared' => ['dirs' => ['bootstrap/cache']]])));
        self::assertNotSame([], $this->errors($this->site(['shared' => ['dirs' => ['storage', 'storage/app']]])));
        self::assertNotSame([], $this->errors($this->site(['shared' => ['dirs' => ['../outside']]])));
        self::assertNotSame([], $this->errors($this->site(['shared' => ['dirs' => ['public/uploads']]])));
    }

    /**
     * @covers-req VAL-09
     * @covers-req VAL-10
     * @covers-req VAL-11
     * @covers-req VAL-12
     */
    public function testRangesAndLists(): void
    {
        self::assertNotSame([], $this->errors($this->site(['releases' => ['keep' => 1]])));
        self::assertNotSame([], $this->errors($this->site(['releases' => ['keep' => 31]])));
        self::assertNotSame([], $this->errors($this->site(['health_check' => ['expect' => '99-200']])));
        self::assertNotSame([], $this->errors($this->site(['health_check' => ['timeout' => 0]])));
        self::assertNotSame([], $this->errors($this->site(['health_check' => ['attempts' => 11]])));
        self::assertSame([], $this->errors($this->site(['health_check' => ['expect' => '200,301,302']])));
        self::assertNotSame([], $this->errors($this->site(['maintenance' => ['secret' => 'short']])));
        self::assertSame([], $this->errors($this->site(['maintenance' => ['secret' => 'let-me-in-123']])));

        $bad = $this->site();
        $bad['custom_commands'] = [['name' => str_repeat('x', 41), 'run' => '', 'timeout' => 0]];
        self::assertCount(3, $this->errors($bad));
        $good = $this->site();
        $good['custom_commands'] = [['name' => 'Sitemap', 'run' => 'php artisan sitemap:generate', 'phase' => 'after_activate', 'when' => 'ask']];
        self::assertSame([], $this->errors($good));
    }

    public function testParseExpect(): void
    {
        self::assertSame([[200, 399]], SiteSchema::parseExpect('200-399'));
        self::assertSame([[200, 200], [301, 302]], SiteSchema::parseExpect('200, 301-302'));
        self::assertNull(SiteSchema::parseExpect('ok'));
        self::assertNull(SiteSchema::parseExpect('600'));
    }

    /**
     * @covers-req SEC-11
     * @covers-req REL-07
     */
    public function testServingTheReleaseRootWithEnvIsRefused(): void
    {
        $e = implode(' ', $this->errors($this->site(['domain' => ['web_dir' => '']])));
        self::assertStringContainsString('would expose .env', $e);

        // A static site shares nothing at the root, so "" is fine.
        self::assertSame([], $this->errors($this->site(['domain' => ['web_dir' => '']], 'static')));
    }

    /**
     * @covers-req CFG-03
     */
    public function testUnknownKeysAreWarnings(): void
    {
        $data = $this->site();
        $data['domian'] = ['name' => 'x'];
        $data['php']['verison'] = '8.2';
        $result = SiteSchema::validate($data);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString("'php.verison'", implode(' ', $result['warnings']));
    }

    public function testPresetsSetTypeDefaults(): void
    {
        $static = $this->site([], 'static');
        self::assertSame('dist', $static['domain']['web_dir']);
        self::assertSame('off', $static['steps']['migrate']);
        self::assertSame([], $static['shared']['files']);

        $user = SiteSchema::withDefaults(['type' => 'static', 'domain' => ['web_dir' => 'build']], (new Presets())->for('static'));
        self::assertSame('build', $user['domain']['web_dir']);
    }

    /**
     * @covers-req CFG-01
     * @covers-req CFG-02
     */
    public function testRegistryLoadSaveAndNewerSchema(): void
    {
        $services = $this->services();
        $registry = $services->sites();
        mkdir($this->root . '/sites/shop', 0711, true);
        $file = $this->root . '/sites/shop/site.yml';
        file_put_contents($file, "schema: 1\nname: shop\ntype: laravel\nrepo: {owner: acme, name: shop}\n"
            . "domain: {name: shop.example.com, docroot: {$this->home}/shop.example.com}\nphp: {version: '8.2'}\n");

        $config = $registry->load('shop');
        self::assertSame('public', $config->webDir());
        self::assertSame('ask', $config->step('migrate'));
        self::assertSame(['shop'], $registry->names());

        $registry->save($config->with('domain.ip', '203.0.113.10'));
        $text = (string) file_get_contents($file);
        self::assertStringStartsWith('# Managed by cpdeploy.', $text);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame('203.0.113.10', $registry->load('shop')->ip());

        file_put_contents($file, "schema: 2\nname: shop\n");
        try {
            $registry->load('shop');
            self::fail('Expected E_CONFIG_NEWER');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CONFIG_NEWER, $e->errorCode);
        }
    }

    /**
     * @covers-req VAL-03
     * @covers-req DOC-01
     */
    public function testRegistryDocrootRules(): void
    {
        $registry = $this->services()->sites();
        $config = new SiteConfig($this->site(['domain' => ['docroot' => $this->home]]));
        self::assertNotSame([], $registry->docrootProblems($config));

        $config = new SiteConfig($this->site(['domain' => ['docroot' => $this->root . '/x']]));
        self::assertNotSame([], $registry->docrootProblems($config));

        $config = new SiteConfig($this->site(['domain' => ['docroot' => '/var/www/html']]));
        self::assertNotSame([], $registry->docrootProblems($config));

        $config = new SiteConfig($this->site(['domain' => ['docroot' => $this->home . '/shop.example.com']]));
        self::assertSame([], $registry->docrootProblems($config));

        // (e): another site already uses it.
        $registry->save($config);
        $blog = new SiteConfig($this->site(['name' => 'blog', 'domain' => ['docroot' => $this->home . '/shop.example.com']]));
        self::assertStringContainsString('already used by the site shop', implode(' ', $registry->docrootProblems($blog)));

        try {
            $registry->load('nosuch');
            self::fail('Expected E_USAGE');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::USAGE, $e->errorCode);
            self::assertStringContainsString('Sites: shop', $e->hint);
        }
    }
}
