<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Tests\Support\TestCase;

/**
 * `cpdeploy token set|test|remove` and the GitHub group of `check`.
 */
final class TokenCommandTest extends TestCase
{
    private const TOKEN = 'github_pat_good_token_123456';

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUapi();
        $this->github = new FakeGitHub($this->tmp . '/github', ['tokens' => [
            self::TOKEN => ['login' => 'jay', 'expires' => '2030-01-01 00:00:00 UTC'],
            'github_pat_soon_expiring_12345' => ['login' => 'jay', 'expires' => '2026-10-02 00:00:00 UTC'],
            'ghp_classictoken1234567890abc' => ['login' => 'jay'],
        ]]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
        $this->env['CPDEPLOY_NOW'] = '2026-09-29T12:00:00Z';
    }

    protected function tearDown(): void
    {
        $this->github->stop();
        parent::tearDown();
    }

    /**
     * @covers-req GH-01
     * @covers-req SEC-03
     */
    public function testSetFromStdinValidatesThenSaves(): void
    {
        $r = $this->runCli(['token', 'set', '--stdin'], self::TOKEN . "\n");

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertStringContainsString('Token saved for GitHub user jay', $r['stdout']);
        self::assertStringContainsString('Expires: 2030-01-01', $r['stdout']);
        self::assertSame(self::TOKEN . "\n", file_get_contents($this->root . '/secrets/github-token'));
        self::assertSame(0600, fileperms($this->root . '/secrets/github-token') & 0777);
        self::assertStringNotContainsString(self::TOKEN, $r['stdout'] . $r['stderr']);

        $test = $this->runCli(['token', 'test']);
        self::assertSame(0, $test['exit']);
        self::assertStringContainsString('The token works (GitHub user jay)', $test['stdout']);
    }

    /**
     * @covers-req GH-04
     */
    public function testAnInvalidTokenIsNotSaved(): void
    {
        $r = $this->runCli(['token', 'set', '--stdin'], "github_pat_unknown_token_000000\n");

        self::assertSame(3, $r['exit']);
        self::assertStringContainsString('The GitHub token is invalid or expired', $r['stderr']);
        self::assertFileDoesNotExist($this->root . '/secrets/github-token');
    }

    /**
     * @covers-req GH-03
     * @covers-req GH-05
     */
    public function testWarningsForExpiringAndClassicTokens(): void
    {
        $soon = $this->runCli(['token', 'set', '--stdin'], "github_pat_soon_expiring_12345\n");
        self::assertStringContainsString('expires in under 14 days', $soon['stdout']);

        $classic = $this->runCli(['token', 'set', '--stdin'], "ghp_classictoken1234567890abc\n");
        self::assertSame(0, $classic['exit']);
        self::assertStringContainsString('classic token', $classic['stdout']);
        self::assertStringContainsString('Expires: never', $classic['stdout']);
    }

    /**
     * @covers-req SEC-14
     */
    public function testRemove(): void
    {
        $this->runCli(['token', 'set', '--stdin'], self::TOKEN . "\n");

        $r = $this->runCli(['token', 'remove']);
        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('GitHub token removed', $r['stdout']);
        self::assertFileDoesNotExist($this->root . '/secrets/github-token');

        $test = $this->runCli(['token', 'test']);
        self::assertSame(3, $test['exit']);
        self::assertStringContainsString('No GitHub token is set', $test['stderr']);
    }

    public function testUnknownActionIsAUsageError(): void
    {
        $r = $this->runCli(['token', 'show']);

        self::assertSame(2, $r['exit']);
        self::assertStringContainsString('cpdeploy token set | test | remove', $r['stderr']);
    }

    /**
     * @covers-req GH-05
     * @covers-req GIT-03
     */
    public function testCheckGitHubGroup(): void
    {
        $none = $this->githubGroup();
        self::assertSame('info', $none['github.token']['status']);
        self::assertSame('ok', $none['github.hostkeys']['status']);

        $this->runCli(['token', 'set', '--stdin'], "github_pat_soon_expiring_12345\n");
        $soon = $this->githubGroup();
        self::assertSame('warn', $soon['github.token']['status']);
        self::assertStringContainsString('GitHub token for jay, expires 2026-10-02', $soon['github.token']['message']);

        file_put_contents($this->root . '/secrets/github-token', "github_pat_revoked_token_00000\n");
        self::assertSame('fail', $this->githubGroup()['github.token']['status']);
    }

    /**
     * @return array<string, array{id: string, status: string, message: string, hint: string}>
     */
    private function githubGroup(): array
    {
        $r = $this->runCli(['check', '--json']);
        $data = json_decode($r['stdout'], true);
        self::assertIsArray($data, $r['stderr']);
        $out = [];
        foreach ($data['groups'] as $group) {
            if ($group['name'] === 'GitHub') {
                foreach ($group['checks'] as $check) {
                    $out[$check['id']] = $check;
                }
            }
        }

        return $out;
    }
}
