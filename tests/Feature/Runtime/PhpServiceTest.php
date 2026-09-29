<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Runtime;

use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Runtime\DomainPhp;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\MemoryReporter;

final class PhpServiceTest extends TestCase
{
    private string $phpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUapi();
        $this->phpRoot = $this->tmp . '/php';
        $this->env['CPDEPLOY_PHP_SEARCH_PATHS'] = $this->phpRoot;
    }

    /**
     * @covers-req PHP-01
     */
    public function testLocatorFindsCliBinariesNewestFirst(): void
    {
        $this->fakePhp($this->phpRoot, '7.4.33');
        $this->fakePhp($this->phpRoot, '8.2.31');
        $this->fakePhp($this->phpRoot, '8.3.31');
        $this->fakePhp($this->phpRoot, '8.2.29', 'alt');
        $this->fakePhp($this->phpRoot, '8.1.34', 'ea', sapi: 'cgi-fcgi');

        $locator = $this->services()->phpLocator();
        $installs = $locator->installs();

        self::assertSame(['ea-php83', 'ea-php82', 'alt-php82', 'ea-php74'], array_map(static fn (PhpInstall $i): string => $i->tag(), $installs));
        self::assertSame('8.3.31', $installs[0]->version);
        self::assertTrue($installs[0]->inMultiPhp, 'Cross-checked with LangPHP::php_get_installed_versions');
        self::assertNull($installs[2]->inMultiPhp, 'alt-php is not a MultiPHP version');
        self::assertNull($locator->find('8.1'), 'php-cgi is not a CLI binary');
        self::assertSame('8.3 (ea), 8.2 (ea), 8.2 (alt), 7.4 (ea)', $locator->describe());
        self::assertSame('alt', $locator->findTag('alt-php82')?->family);
    }

    /**
     * @covers-req PHP-02
     */
    public function testExtensions(): void
    {
        $bin = $this->fakePhp($this->phpRoot, '8.2.31', modules: ['Core', 'PDO', 'pdo_mysql', 'Zend OPcache', 'intl']);

        $ext = $this->services()->phpLocator()->extensions($bin);

        self::assertContains('pdo_mysql', $ext);
        self::assertContains('intl', $ext);
        self::assertContains('opcache', $ext);
        self::assertNotContains('[php modules]', $ext);
    }

    /**
     * @covers-req PHP-04
     */
    public function testResolveOrEPhpMissing(): void
    {
        $bin = $this->fakePhp($this->phpRoot, '8.2.31');
        $this->fakePhp($this->phpRoot, '8.3.31');
        $php = $this->services()->php();

        self::assertSame($bin, $php->resolve('8.2')->binary);
        try {
            $php->resolve('8.4');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::PHP_MISSING, $e->errorCode);
            self::assertStringContainsString('Installed: 8.3 (ea), 8.2 (ea)', $e->getMessage());
        }
    }

    /**
     * @covers-req PHP-03
     */
    public function testDomainPhpFromItsOwnMultiPhpVersion(): void
    {
        $domain = $this->services()->domains()->find('site21.example.test');
        self::assertNotNull($domain);
        $php = $this->services()->php()->domainPhp($domain);

        self::assertNotNull($php);
        self::assertSame('ea-php80', $php->tag());
        self::assertSame(DomainPhp::SOURCE_MULTIPHP, $php->source);
        self::assertFalse($php->inherited);
    }

    /**
     * @covers-req PHP-03
     */
    public function testInheritedDomainUsesTheSystemDefault(): void
    {
        $this->uapiFixture('LangPHP', 'php_get_vhost_versions', '{"result":{"status":1,"data":[{"vhost":"shop.test","version":"ea-php81","documentroot":"/home/u/shop","php_fpm":1,"main_domain":0,"phpversion_source":{"system_default":1}}]}}');
        $this->uapiFixture('LangPHP', 'php_get_system_default_version', '{"result":{"status":1,"data":{"version":"ea-php83"}}}');

        $php = $this->services()->php()->domainPhp(new Domain('shop.test', Domain::ADDON, '/home/u/shop', '192.0.2.1'));

        self::assertNotNull($php);
        self::assertSame('ea-php83', $php->tag());
        self::assertSame(DomainPhp::SOURCE_SYSTEM_DEFAULT, $php->source);
        self::assertTrue($php->inherited);
    }

    /**
     * On CloudLinux, an inheriting domain runs what PHP Selector chose; the CLI at
     * /usr/local/bin/php reflects that choice from inside the docroot.
     *
     * @covers-req PHP-03
     */
    public function testInheritedDomainOnCloudLinuxUsesPhpSelector(): void
    {
        $sys = $this->tmp . '/sys';
        mkdir($sys . '/etc', 0777, true);
        file_put_contents($sys . '/etc/cloudlinux-release', 'CloudLinux release 9.4');
        $this->fakePhp($sys . '/opt/alt', '8.2.29', 'alt');
        mkdir($sys . '/usr/local/bin', 0777, true);
        file_put_contents($sys . '/usr/local/bin/php', "#!/bin/sh\n[ \"\$(pwd)\" = \"{$this->tmp}/docroot\" ] || exit 9\necho '/opt/alt/php82/usr/bin/php|8.2.29'\n");
        chmod($sys . '/usr/local/bin/php', 0755);
        mkdir($this->tmp . '/docroot');
        $this->env['CPDEPLOY_SYSTEM_ROOT'] = $sys;
        $this->uapiFixture('LangPHP', 'php_get_vhost_versions', '{"result":{"status":1,"data":[{"vhost":"shop.test","version":"ea-php81","documentroot":"' . $this->tmp . '/docroot","php_fpm":0,"main_domain":0,"phpversion_source":{"system_default":1}}]}}');

        $php = $this->services()->php()->domainPhp(new Domain('shop.test', Domain::ADDON, $this->tmp . '/docroot', '192.0.2.1'));

        self::assertNotNull($php);
        self::assertSame('alt-php82', $php->tag());
        self::assertSame(DomainPhp::SOURCE_SELECTOR, $php->source);
    }

    /**
     * @covers-req PHP-07
     */
    public function testReleaseUsesItsRecordedPhpWhileItExists(): void
    {
        $old = $this->fakePhp($this->phpRoot, '8.1.34');
        $site = $this->fakePhp($this->phpRoot, '8.3.31');
        $php = $this->services()->php();
        $sitePhp = $php->resolve('8.3');
        $reporter = new MemoryReporter();

        self::assertSame($old, $php->forRelease($old, $sitePhp, $reporter));
        self::assertSame($site, $php->forRelease('/opt/cpanel/ea-php56/root/usr/bin/php', $sitePhp, $reporter));
        self::assertStringContainsString('no longer exists', $reporter->of('warn')[0]);
        self::assertSame($site, $php->forRelease(null, $sitePhp, $reporter));
    }

    /**
     * @covers-req GL-03
     */
    public function testPhpChangeOrdering(): void
    {
        self::assertSame(PhpService::CHANGE_NONE, PhpService::phpChange('ea-php82', 'ea-php82'));
        self::assertSame(PhpService::CHANGE_UPGRADE, PhpService::phpChange('ea-php82', 'ea-php83'));
        self::assertSame(PhpService::CHANGE_UPGRADE, PhpService::phpChange('ea-php74', 'ea-php80'));
        self::assertSame(PhpService::CHANGE_DOWNGRADE, PhpService::phpChange('ea-php83', 'ea-php82'));
        self::assertSame(PhpService::CHANGE_DOWNGRADE, PhpService::phpChange('ea-php80', 'ea-php74'));
        self::assertSame(PhpService::CHANGE_FAMILY, PhpService::phpChange('alt-php82', 'ea-php82'));
        self::assertSame(PhpService::CHANGE_NONE, PhpService::phpChange(null, 'ea-php82'));
        self::assertTrue(PhpService::switchesBeforeCode(PhpService::CHANGE_UPGRADE));
        self::assertTrue(PhpService::switchesBeforeCode(PhpService::CHANGE_FAMILY));
        self::assertFalse(PhpService::switchesBeforeCode(PhpService::CHANGE_DOWNGRADE));
        self::assertFalse(PhpService::switchesBeforeCode(PhpService::CHANGE_NONE));
    }

    /**
     * @covers-req PHP-05
     */
    public function testPlatformProblemsFromJsonAndText(): void
    {
        $json = (string) json_encode([
            ['name' => 'ext-intl', 'version' => null, 'status' => 'missing', 'failed_requirement' => ['source' => 'laravel/framework', 'type' => 'requires', 'target' => 'ext-intl', 'constraint' => '*'], 'provider' => null],
            ['name' => 'ext-mbstring', 'version' => '8.1.34', 'status' => 'success', 'failed_requirement' => null, 'provider' => null],
            ['name' => 'php', 'version' => '8.1.34', 'status' => 'failed', 'failed_requirement' => ['source' => 'symfony/console', 'type' => 'requires', 'target' => 'php', 'constraint' => '>=8.2'], 'provider' => null],
        ]);
        self::assertSame(['ext-intl (missing)', 'php 8.1.34 (requires >=8.2)'], PhpService::platformProblems($json, 2));
        self::assertSame([], PhpService::platformProblems('[{"name":"php","version":"8.3.1","status":"success"}]', 0));

        $text = "Checking platform requirements for packages in the vendor dir\n"
            . "ext-intl      n/a      laravel/framework requires ext-intl (*)     missing\n"
            . "ext-mbstring  8.1.34                                             success\n"
            . "php           8.1.34   symfony/console requires php (>=8.2)       failed\n";
        self::assertSame(['ext-intl (missing)', 'php (failed)'], PhpService::platformProblems($text, 2));
        self::assertSame(['the platform check failed (exit code 1)'], PhpService::platformProblems('garbage', 1));
        self::assertSame([], PhpService::platformProblems('garbage', 0));
    }

    /**
     * @covers-req PHP-05
     */
    public function testProblemsWithoutLock(): void
    {
        $this->fakePhp($this->phpRoot, '8.1.34', modules: ['Core', 'mbstring']);
        $php = $this->services()->php();
        $install = $php->resolve('8.1');

        self::assertSame(
            ['php 8.1.34 (requires ^8.2)', 'ext-intl (missing)'],
            $php->problemsWithoutLock(['php' => '^8.2', 'ext-mbstring' => '*', 'ext-intl' => '*', 'laravel/framework' => '^11'], $install),
        );
        self::assertSame([], $php->problemsWithoutLock(['php' => '>=8.1'], $install));
    }

    public function testTags(): void
    {
        self::assertSame(['ea', '8.2'], PhpInstall::parseTag('ea-php82'));
        self::assertSame(['alt', '7.4'], PhpInstall::parseTag('alt-php74'));
        self::assertNull(PhpInstall::parseTag('php82'));
        self::assertSame('ea-php74', PhpInstall::tagFor('ea', '7.4'));
    }

    public function testCloudLinuxIsNotAssumedFromAltFolders(): void
    {
        self::assertFalse((new CloudLinux($this->tmp))->isCloudLinux());
    }
}
