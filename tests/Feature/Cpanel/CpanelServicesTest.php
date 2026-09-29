<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Cpanel;

use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Cpanel\Uapi;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

/**
 * cPanel services against the fake uapi, which answers with output captured on a
 * real cPanel 11.138 / AlmaLinux 8 server (tests/Fixtures/uapi).
 */
final class CpanelServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUapi();
    }

    /**
     * @covers-req CP-01
     * @covers-req CP-04
     */
    public function testDomainsFromRealOutput(): void
    {
        $domains = $this->services()->domains();
        $all = $domains->all();

        self::assertCount(7, $all);
        self::assertSame(Domain::MAIN, $all[0]->type);
        self::assertSame('site52.example.test', $all[0]->name);
        self::assertSame('/home/cpuser/public_html', $all[0]->documentRoot);
        self::assertSame('192.0.2.10', $all[0]->ip);
        self::assertSame('ea-php81', $all[0]->phpVersion);

        $addon = $domains->find('SITE41.example.test.');
        self::assertNotNull($addon);
        self::assertSame(Domain::ADDON, $addon->type);
        self::assertSame('/home/cpuser/public_html/site41.example.test/public', $addon->documentRoot);
        self::assertSame('site13.example.test', $addon->serverName);
        self::assertTrue($addon->selectable());
        self::assertSame(Domain::SUB, $domains->find('site21.example.test')?->type);
        self::assertNull($domains->find('nope.example.test'));

        self::assertSame(['call' => 'DomainInfo::domains_data', 'args' => ['format' => 'hash']], $this->uapiCalls()[0]);
    }

    public function testParkedDomainsAreListedButNotSelectable(): void
    {
        $this->uapiFixture('DomainInfo', 'domains_data', (string) json_encode(['result' => ['status' => 1, 'data' => [
            'main_domain' => ['domain' => 'a.test', 'documentroot' => '/home/u/public_html/', 'ip' => '192.0.2.1'],
            'addon_domains' => [], 'sub_domains' => [],
            'parked_domains' => ['alias.test'],
        ]]]));
        $all = $this->services()->domains()->all();

        self::assertSame('/home/u/public_html', $all[0]->documentRoot);
        self::assertSame(Domain::PARKED, $all[1]->type);
        self::assertFalse($all[1]->selectable());
        self::assertSame('192.0.2.1', $all[1]->ip);
    }

    /**
     * @covers-req CP-04
     */
    public function testMultiPhpFromRealOutput(): void
    {
        $php = $this->services()->multiPhp();

        self::assertSame(['ea-php74', 'ea-php80', 'ea-php81', 'ea-php82', 'ea-php83'], $php->installedVersions());
        self::assertSame('ea-php81', $php->systemDefault());
        $vhost = $php->vhost('site21.example.test');
        self::assertNotNull($vhost);
        self::assertSame('ea-php80', $vhost->version);
        self::assertTrue($vhost->phpFpm);
        self::assertFalse($vhost->inherited);
        self::assertSame('/home/cpuser/public_html/site21.example.test/public', $vhost->documentRoot);
        self::assertTrue($php->vhost('site52.example.test')?->mainDomain);
    }

    public function testInheritDetection(): void
    {
        self::assertFalse(MultiPhpService::isInherited(['domain' => 'shop.test'], 'shop.test'));
        self::assertTrue(MultiPhpService::isInherited(['system_default' => 1], 'shop.test'));
        self::assertTrue(MultiPhpService::isInherited(['domain' => 'parent.test'], 'shop.parent.test'));
        self::assertTrue(MultiPhpService::isInherited(null, 'shop.test'));
    }

    /**
     * @covers-req CP-01
     */
    public function testSetVhostVersionUriEncodesArguments(): void
    {
        $this->services()->multiPhp()->setVhostVersion('shop.example.test', 'ea-php83');
        $this->services()->mysql()->grantAll('cpuser_shop', 'cpuser_shop');

        $calls = $this->uapiCalls();
        self::assertSame(['vhost' => 'shop.example.test', 'version' => 'ea-php83'], $calls[0]['args']);
        self::assertSame('ALL PRIVILEGES', $calls[1]['args']['privileges'], 'Sent URI-encoded, decoded by uapi');
    }

    /**
     * @covers-req CP-04
     */
    public function testMysqlFromRealOutput(): void
    {
        $mysql = $this->services()->mysql();
        $r = $mysql->restrictions();

        self::assertSame('cpuser_', $r->prefix);
        self::assertSame(64, $r->maxDatabaseNameLength);
        self::assertSame(32, $r->maxUserNameLength);
        self::assertSame(['cpuser_db1'], $mysql->databases()['cpuser_db1']);
        self::assertContains('cpuser_db3', $mysql->users()['cpuser_db3']);
    }

    public function testMysqlPrefixOffAndPlanFieldNames(): void
    {
        $this->uapiFixture('Mysql', 'get_restrictions', '{"result":{"status":1,"data":{"database_name_length_limit":48,"database_user_name_length_limit":16}}}');
        $r = $this->services()->mysql()->restrictions();

        self::assertNull($r->prefix);
        self::assertSame(48, $r->maxDatabaseNameLength);
        self::assertSame(16, $r->maxUserNameLength);
    }

    /**
     * @covers-req SEC-07
     */
    public function testCreateUserOnlyAcceptsAlphanumericPasswords(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->services()->mysql()->createUser('cpuser_shop', 'pa ss&word');
    }

    public function testQuotaFromRealOutputIsUnlimited(): void
    {
        $q = $this->services()->quota()->quota();

        self::assertEqualsWithDelta(49704.84, $q->megabytesUsed, 0.001);
        self::assertNull($q->megabyteLimit);
        self::assertNull($q->inodeLimit);
        self::assertSame(1197815, $q->inodesUsed);
        self::assertNull($q->diskPercent());
    }

    public function testQuotaWithLimits(): void
    {
        $this->uapiFixture('Quota', 'get_quota_info', '{"result":{"status":1,"data":{"megabytes_used":900,"megabyte_limit":"1000.00","inodes_used":"50000","inode_limit":"100000"}}}');
        $q = $this->services()->quota()->quota();

        self::assertSame(1000.0, $q->megabyteLimit);
        self::assertSame(100.0, $q->megabytesFree());
        self::assertSame(90.0, $q->diskPercent());
        self::assertSame(50000, $q->inodesFree());
    }

    /**
     * Real behaviour: uapi exits 0 on failure and prints a warning before the JSON.
     *
     * @covers-req CP-01
     */
    public function testRealFailureOutputBecomesEUapi(): void
    {
        $this->uapiFixture('LangPHP', 'php_get_installed_versions', (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/uapi-raw/error-unknown-function.txt'), true);

        try {
            $this->services()->multiPhp()->installedVersions();
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::UAPI, $e->errorCode);
            self::assertStringContainsString('cPanel refused LangPHP::php_get_installed_versions', $e->getMessage());
            self::assertStringContainsString('could not find the function', $e->getMessage());
            self::assertSame(3, $e->exitCode());
        }
    }

    public function testFailureExitCodeCanBeRaisedForGoLive(): void
    {
        $this->uapiFail('LangPHP::php_set_vhost_versions');

        try {
            $this->services()->multiPhp()->setVhostVersion('shop.test', 'ea-php82', 6);
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(6, $e->exitCode());
            self::assertStringContainsString('Fake failure', $e->getMessage());
        }
    }

    public function testGarbageOutputIsAClearError(): void
    {
        $this->uapiFixture('Quota', 'get_quota_info', "Internal Server Error\n", true);

        $this->expectExceptionMessage('cPanel refused Quota::get_quota_info: Internal Server Error');
        $this->services()->quota()->quota();
    }

    /**
     * @covers-req CP-02
     * @covers-req CP-03
     */
    public function testMissingUapiIsENotCpanel(): void
    {
        $this->env['CPDEPLOY_UAPI_BIN'] = 'uapi-missing-xyz';

        try {
            $this->services()->domains()->all();
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::NOT_CPANEL, $e->errorCode);
        }
    }

    public function testDecodeSkipsWarningLines(): void
    {
        self::assertSame(['result' => ['status' => 1]], Uapi::decode("warn [uapi] something\n{\"result\":{\"status\":1}}\n"));
        self::assertNull(Uapi::decode('nothing here'));
    }

    public function testCloudLinuxDetection(): void
    {
        $root = $this->tmp . '/sys';
        mkdir($root . '/opt/alt/php82/usr/bin', 0777, true);
        touch($root . '/opt/alt/php82/usr/bin/php');
        $cl = new CloudLinux($root);

        self::assertFalse($cl->isCloudLinux(), 'alt-php alone (Imunify360) is not CloudLinux');
        self::assertFalse($cl->selectorAvailable());

        mkdir($root . '/etc');
        file_put_contents($root . '/etc/cloudlinux-release', "CloudLinux release 9.4\n");
        self::assertTrue($cl->isCloudLinux());
        self::assertSame('CloudLinux release 9.4', $cl->release());
        self::assertTrue($cl->selectorAvailable());
    }
}
