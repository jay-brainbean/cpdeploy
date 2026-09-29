<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\TestCase;

/**
 * Runs bin/cpdeploy in a subprocess with a temporary HOME.
 */
final class CliTest extends TestCase
{
    /**
     * @covers-req CLI-02
     */
    public function testVersion(): void
    {
        $r = $this->runCli(['--version']);

        self::assertSame(0, $r['exit']);
        self::assertMatchesRegularExpression('/^cpdeploy \S+ · PHP \d+\.\d+\.\d+ \(.+\)/', $r['stdout']);
        self::assertDirectoryDoesNotExist($this->root, '--version creates nothing');
    }

    /**
     * @covers-req LAY-02
     * @covers-req CFG-01
     */
    public function testFirstRunCreatesTheSkeletonWithModes(): void
    {
        $this->fakeUapi();
        $r = $this->runCli(['check', '--json']);

        self::assertContains($r['exit'], [0, 3], $r['stderr']);
        $expected = ['' => 0711, '/app' => 0755, '/secrets' => 0700, '/tools' => 0711, '/tmp' => 0700, '/removed' => 0700, '/sites' => 0711];
        foreach ($expected as $dir => $mode) {
            self::assertDirectoryExists($this->root . $dir);
            self::assertSame($mode, fileperms($this->root . $dir) & 0777, "mode of ~/cpdeploy{$dir}");
        }
        self::assertSame(0600, fileperms($this->root . '/config.yml') & 0777);
        self::assertStringStartsWith('# Managed by cpdeploy.', (string) file_get_contents($this->root . '/config.yml'));
    }

    /**
     * @covers-req LAY-02
     * @covers-req SEC-01
     */
    public function testModesAreReappliedOnEveryStart(): void
    {
        $this->fakeUapi();
        $this->runCli(['check']);
        chmod($this->root, 0755);
        chmod($this->root . '/secrets', 0755);
        chmod($this->root . '/config.yml', 0644);
        file_put_contents($this->root . '/secrets/github-token', 'x');
        chmod($this->root . '/secrets/github-token', 0644);

        $this->runCli(['check']);

        self::assertSame(0711, fileperms($this->root) & 0777);
        self::assertSame(0700, fileperms($this->root . '/secrets') & 0777);
        self::assertSame(0600, fileperms($this->root . '/config.yml') & 0777);
        self::assertSame(0600, fileperms($this->root . '/secrets/github-token') & 0777);
    }

    /**
     * @covers-req LAY-03
     */
    public function testStaleTmpEntriesAreRemovedAtStart(): void
    {
        $this->fakeUapi();
        mkdir($this->root . '/tmp', 0700, true);
        mkdir($this->root . '/tmp/cpd-old');
        mkdir($this->root . '/tmp/cpd-new');
        mkdir($this->root . '/tmp/not-ours');
        touch($this->root . '/tmp/cpd-old', time() - 100000);
        touch($this->root . '/tmp/not-ours', time() - 100000);

        $this->runCli(['check']);

        self::assertDirectoryDoesNotExist($this->root . '/tmp/cpd-old');
        self::assertDirectoryExists($this->root . '/tmp/cpd-new');
        self::assertDirectoryExists($this->root . '/tmp/not-ours');
    }

    /**
     * @covers-req NI-05
     */
    public function testCheckJsonOutputsOnlyJson(): void
    {
        $this->fakeUapi();
        $r = $this->runCli(['check', '--json']);

        $data = json_decode($r['stdout'], true);
        self::assertIsArray($data, 'stdout is a single JSON document: ' . $r['stdout']);
        self::assertSame(1, $data['schema']);
        self::assertSame(['Tool', 'Programs', 'cPanel', 'Account', 'Network', 'GitHub'], array_column($data['groups'], 'name'));
        $failed = false;
        foreach ($data['groups'] as $group) {
            foreach ($group['checks'] as $check) {
                self::assertSame(['id', 'status', 'message', 'hint'], array_keys($check));
                self::assertContains($check['status'], ['ok', 'warn', 'fail', 'info']);
                $failed = $failed || $check['status'] === 'fail';
            }
        }
        self::assertSame($failed ? 3 : 0, $r['exit'], 'Exit 3 exactly when a check failed');
    }

    public function testCheckHumanOutputUsesAsciiWithoutUtf8(): void
    {
        $this->fakeUapi();
        $this->env['LANG'] = 'C';
        $r = $this->runCli(['check', '--no-ansi']);

        self::assertStringContainsString('cpdeploy · Server check', $r['stdout']);
        self::assertStringContainsString('[ok] ', $r['stdout']);
        self::assertStringNotContainsString('✓', $r['stdout']);
    }

    /**
     * @covers-req CLI-02
     */
    public function testUsageErrorsExit2WithTheErrorFormat(): void
    {
        $r = $this->runCli(['check', '--no-such-option']);

        self::assertSame(2, $r['exit']);
        self::assertStringContainsString('Live site: not changed', $r['stderr']);
        self::assertStringContainsString('Fix: Run: cpdeploy help <command>', $r['stderr']);
    }

    public function testNoArgumentsWithoutTtyListsCommands(): void
    {
        $r = $this->runCli([]);

        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('check', $r['stdout']);
    }

    /**
     * @covers-req ARC-09
     */
    public function testInvalidConfigIsReportedWithExit2(): void
    {
        mkdir($this->root, 0711, true);
        file_put_contents($this->root . '/config.yml', "schema: 1\ntimeouts:\n  git: -1\n");
        $r = $this->runCli(['check', '--json']);

        self::assertSame(2, $r['exit']);
        self::assertStringContainsString('timeouts.git', $r['stderr']);
        $data = json_decode($r['stdout'], true);
        self::assertIsArray($data);
        self::assertSame('E_CONFIG_INVALID', $data['error']['code']);
    }
}
