<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Check;

use Cpdeploy\Check\CheckResult;
use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Tests\Support\TestCase;

/**
 * The cPanel, Account and Network groups of `cpdeploy check` (§9.7).
 */
final class ServerCheckGroupsTest extends TestCase
{
    /**
     * @param list<string> $groups
     * @return array<string, CheckResult>
     */
    private function checks(array $groups): array
    {
        $out = [];
        foreach ($this->services()->serverCheck()->run($groups) as $group) {
            foreach ($group->checks as $check) {
                $out[$check->id] = $check;
            }
        }

        return $out;
    }

    public function testCpanelGroupFromRealOutput(): void
    {
        $this->fakeUapi();
        $this->env['CPDEPLOY_PHP_SEARCH_PATHS'] = $this->tmp . '/php';
        $this->fakePhp($this->tmp . '/php', '8.3.31');
        $this->fakePhp($this->tmp . '/php', '8.2.31');

        $c = $this->checks([ServerCheck::GROUP_CPANEL]);

        self::assertSame(CheckResult::OK, $c['cpanel.uapi']->status);
        self::assertSame('cPanel answers (7 domains)', $c['cpanel.uapi']->message);
        self::assertSame('MultiPHP: ea-php74, ea-php80, ea-php81, ea-php82, ea-php83 (default ea-php81)', $c['cpanel.multiphp']->message);
        self::assertSame('PHP for sites: 8.3.31 (ea), 8.2.31 (ea)', $c['cpanel.php']->message);
        self::assertSame('MySQL available (names start with cpuser_)', $c['cpanel.mysql']->message);
        self::assertSame(CheckResult::INFO, $c['cpanel.cloudlinux']->status);
        self::assertStringContainsString('Not CloudLinux', $c['cpanel.cloudlinux']->message);
    }

    public function testCpanelGroupFailuresAndMissingUapi(): void
    {
        $this->fakeUapi();
        $this->uapiFail('LangPHP::php_get_installed_versions', 'Mysql::get_restrictions');
        $c = $this->checks([ServerCheck::GROUP_CPANEL]);
        self::assertSame(CheckResult::FAIL, $c['cpanel.multiphp']->status);
        self::assertSame(CheckResult::WARN, $c['cpanel.mysql']->status);
        self::assertSame(CheckResult::WARN, $c['cpanel.php']->status);

        $this->env['CPDEPLOY_UAPI_BIN'] = 'no-uapi-here';
        $c = $this->checks([ServerCheck::GROUP_CPANEL, ServerCheck::GROUP_ACCOUNT]);
        self::assertSame(['cpanel.uapi', 'account.quota'], array_keys($c));
        self::assertSame(CheckResult::FAIL, $c['cpanel.uapi']->status);
        self::assertSame(CheckResult::INFO, $c['account.quota']->status);
    }

    /**
     * @covers-req CP-04
     */
    public function testAccountGroupUnlimitedFromRealOutput(): void
    {
        $this->fakeUapi();
        $c = $this->checks([ServerCheck::GROUP_ACCOUNT]);

        self::assertSame(CheckResult::OK, $c['account.disk']->status);
        self::assertSame('Disk: 48.5 GB used (no limit)', $c['account.disk']->message);
        self::assertSame('Inodes: 1,197,815 used (no limit)', $c['account.inodes']->message);
    }

    public function testAccountThresholds(): void
    {
        $this->fakeUapi();
        $this->uapiFixture('Quota', 'get_quota_info', '{"result":{"status":1,"data":{"megabytes_used":850,"megabyte_limit":"1000","inodes_used":97000,"inode_limit":"100000"}}}');
        $c = $this->checks([ServerCheck::GROUP_ACCOUNT]);
        self::assertSame(CheckResult::WARN, $c['account.disk']->status, '85 % > 80 %');
        self::assertSame(CheckResult::FAIL, $c['account.inodes']->status, '97 % > 95 %');
        self::assertStringContainsString('(85%)', $c['account.disk']->message);

        $this->uapiFixture('Quota', 'get_quota_info', '{"result":{"status":1,"data":{"megabytes_used":100,"megabyte_limit":"1000","inodes_used":10,"inode_limit":"100000"}}}');
        self::assertSame(CheckResult::OK, $this->checks([ServerCheck::GROUP_ACCOUNT])['account.disk']->status);
    }

    public function testNetworkGroup(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $open = (string) stream_socket_get_name($listener, false);

        // Port 22 blocked, 443 open: fine (GIT-04 fallback). Downloads unreachable: warnings only.
        $this->env['CPDEPLOY_TCP_OVERRIDE'] = "ssh.github.com:443={$open},*=127.0.0.1:1";
        $c = $this->checks([ServerCheck::GROUP_NETWORK]);
        self::assertSame(CheckResult::OK, $c['network.github']->status);
        self::assertStringContainsString('port 22 is blocked', $c['network.github']->message);
        self::assertSame(CheckResult::WARN, $c['network.composer']->status);
        self::assertSame("Can't reach getcomposer.org:443", $c['network.composer']->message);
        self::assertSame(CheckResult::WARN, $c['network.node']->status);
        self::assertArrayNotHasKey('network.api', $c, 'Only checked with a token');

        // Both GitHub ports blocked: a failure. With a token, the API is checked too.
        $this->env['CPDEPLOY_TCP_OVERRIDE'] = "nodejs.org:443={$open},getcomposer.org:443={$open},*=127.0.0.1:1";
        mkdir($this->root . '/secrets', 0700, true);
        file_put_contents($this->root . '/secrets/github-token', 'x');
        $c = $this->checks([ServerCheck::GROUP_NETWORK]);
        self::assertSame(CheckResult::FAIL, $c['network.github']->status);
        self::assertSame(CheckResult::FAIL, $c['network.api']->status);
        self::assertSame(CheckResult::OK, $c['network.composer']->status);
        self::assertSame(CheckResult::OK, $c['network.node']->status);
        fclose($listener);
    }
}
