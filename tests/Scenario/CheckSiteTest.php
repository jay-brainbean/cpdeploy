<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Git\HostKeys;
use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Tests\Support\FakeGitHub;

/**
 * `cpdeploy check [site] [--probe]` per-site group (§9.7) and
 * `--refresh-host-keys` (GIT-03).
 */
final class CheckSiteTest extends DeployScenario
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->home . '/.ssh', 0700, true);
        file_put_contents($this->home . '/.ssh/cpdeploy_shop', "unused with file:// remotes\n");
    }

    /**
     * @covers-req HTTP-04
     */
    public function testSiteGroupAfterADeploy(): void
    {
        $before = $this->check(['check', self::SITE, '--json']);
        $site = $this->group($before, 'Site shop');
        self::assertSame('ok', $site['site.key']['status']);
        self::assertSame('ok', $site['site.php']['status']);
        self::assertStringContainsString('PHP 8.2.31', $site['site.php']['message']);
        self::assertSame('info', $site['site.docroot']['status'], 'not live yet');
        self::assertSame('info', $site['site.current']['status']);

        $this->assertExit(0, $this->deploy());
        chmod($this->siteFiles . '/shared/.env', 0644);

        $after = $this->check(['check', self::SITE, '--json', '--probe']);
        $site = $this->group($after, 'Site shop');
        self::assertSame('ok', $site['site.docroot']['status']);
        self::assertSame('ok', $site['site.current']['status']);
        self::assertSame('ok', $site['site.node']['status']);
        self::assertSame('ok', $site['site.shared']['status']);
        self::assertSame('ok', $site['site.state']['status']);
        self::assertSame('warn', $site['site.env.mode']['status']);
        // The harness web server runs the PHP running the tests: a match with the
        // site's 8.2 is ✓, anything else ✗.
        $served = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        self::assertSame($served === '8.2' ? 'ok' : 'fail', $site['site.probe']['status']);
        self::assertStringContainsString('The domain serves PHP ' . $served, $site['site.probe']['message']);
        self::assertSame([], glob($this->siteFiles . '/current/public/.cpd-probe-*') ?: [], 'the probe file is removed');

        // Plain output offers the .env fix; --yes applies it.
        $r = $this->runCli(['check', self::SITE, '--yes']);
        self::assertStringContainsString('shared/.env is now 600', $r['stdout']);
        self::assertSame(0600, fileperms($this->siteFiles . '/shared/.env') & 0777);

        $unknown = $this->runCli(['check', 'nope']);
        $this->assertExit(2, $unknown);
    }

    /**
     * @covers-req GIT-03
     */
    public function testRefreshHostKeys(): void
    {
        $keys = [];
        foreach (file(dirname(__DIR__, 2) . '/resources/github_known_hosts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'github.com ')) {
                $keys[] = substr($line, strlen('github.com '));
            }
        }
        $github = new FakeGitHub($this->tmp . '/github', ['meta_ssh_keys' => $keys]);
        $this->env['CPDEPLOY_GITHUB_API'] = $github->url();
        try {
            $ask = $this->runCli(['check', '--refresh-host-keys']);
            $this->assertExit(2, $ask);
            self::assertStringContainsString(HostKeys::PUBLISHED['ssh-ed25519'] . '  (as published)', $ask['stdout']);

            $r = $this->runCli(['check', '--refresh-host-keys', '--yes']);
            $this->assertExit(0, $r);
            $written = (string) file_get_contents($this->root . '/known_hosts');
            self::assertStringStartsWith(HostKeys::REFRESHED, $written);
            self::assertCount(6, HostKeys::parse($written), 'three keys for each of the two hosts');

            // The next start keeps the refreshed file.
            $this->runCli(['status']);
            self::assertSame($written, file_get_contents($this->root . '/known_hosts'));
        } finally {
            $github->stop();
        }
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function check(array $args): array
    {
        $r = $this->runCli($args);
        $data = json_decode($r['stdout'], true);
        self::assertIsArray($data, $r['stdout'] . $r['stderr']);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array{id: string, status: string, message: string, hint: string}>
     */
    private function group(array $data, string $name): array
    {
        foreach ($data['groups'] as $group) {
            if ($group['name'] === $name) {
                return array_column($group['checks'], null, 'id');
            }
        }
        self::fail("No group {$name}");
    }
}
