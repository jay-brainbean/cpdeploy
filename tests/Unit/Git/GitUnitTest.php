<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Git;

use Cpdeploy\Git\GitRepository;
use Cpdeploy\Git\HostKeys;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Git\SshCommand;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\ProcessResult;
use Cpdeploy\Tests\Support\TestCase;

final class GitUnitTest extends TestCase
{
    /**
     * @covers-req GIT-01
     */
    public function testRepoUrlAcceptedForms(): void
    {
        foreach ([
            'acme/shop', 'acme/shop.git', ' acme/shop ',
            'git@github.com:acme/shop.git', 'git@github.com:acme/shop',
            'https://github.com/acme/shop', 'https://github.com/acme/shop.git', 'https://github.com/acme/shop/',
            'ssh://git@ssh.github.com:443/acme/shop.git', 'ssh://git@github.com/acme/shop.git',
        ] as $input) {
            $url = RepoUrl::parse($input);
            self::assertSame('acme/shop', $url->fullName(), $input);
        }
        self::assertSame('my-org/my.app_v2', RepoUrl::parse('git@github.com:my-org/my.app_v2.git')->fullName());
    }

    public function testRepoUrlRejects(): void
    {
        foreach (['', 'shop', 'https://gitlab.com/acme/shop', 'acme/shop/extra', 'git@bitbucket.org:a/b.git', '-acme/shop', 'acme/..', 'https://github.com/acme', 'acme/sh op'] as $input) {
            try {
                RepoUrl::parse($input);
                self::fail("Accepted: {$input}");
            } catch (CpdeployException $e) {
                self::assertStringContainsString("isn't a GitHub repository address", $e->getMessage());
                self::assertSame('Examples: acme/shop or git@github.com:acme/shop.git', $e->hint);
            }
        }
    }

    /**
     * @covers-req GIT-01
     */
    public function testTransportUrls(): void
    {
        $url = RepoUrl::parse('acme/shop');

        self::assertSame('git@github.com:acme/shop.git', $url->remote(RepoUrl::TRANSPORT_SSH22));
        self::assertSame('ssh://git@ssh.github.com:443/acme/shop.git', $url->remote(RepoUrl::TRANSPORT_SSH443));
        self::assertSame('https://github.com/acme/shop/settings/keys/new', $url->newKeyUrl());
    }

    /**
     * The embedded keys produce GitHub's published fingerprints, for both hosts.
     *
     * @covers-req GIT-03
     */
    public function testEmbeddedHostKeysMatchPublishedFingerprints(): void
    {
        $keys = $this->services()->hostKeys();

        self::assertSame([], $keys->problems());
        $parsed = HostKeys::parse($keys->embedded());
        self::assertCount(6, $parsed);
        self::assertSame(['github.com', 'ssh-ed25519', 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU'], $parsed[0]);
    }

    /**
     * @covers-req GIT-03
     */
    public function testTamperedKeysAreDetectedAndInstallIsIdempotent(): void
    {
        mkdir($this->root, 0711, true);
        $bad = $this->tmp . '/bad_known_hosts';
        file_put_contents($bad, str_replace('AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl', 'AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJm', (string) file_get_contents(HostKeys::embeddedPath())));
        $keys = new HostKeys($this->services()->fs(), $this->root . '/known_hosts', $bad);
        self::assertNotSame([], $keys->problems());

        $good = $this->services()->hostKeys();
        $good->install();
        self::assertSame(0644, fileperms($this->root . '/known_hosts') & 0777);
        self::assertTrue($good->installedMatches());
        file_put_contents($this->root . '/known_hosts', 'evil.example ssh-rsa AAAA');
        self::assertFalse($good->installedMatches());
        $good->install();
        self::assertTrue($good->installedMatches());
    }

    /**
     * @covers-req GIT-02
     * @covers-req SEC-04
     */
    public function testSshCommand(): void
    {
        $cmd = SshCommand::build('/home/u/.ssh/cpdeploy_shop', '/home/u/cpdeploy/known_hosts');

        self::assertSame(
            'ssh -F /dev/null -i /home/u/.ssh/cpdeploy_shop -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes'
            . ' -o UserKnownHostsFile=/home/u/cpdeploy/known_hosts -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=20'
            . ' -o ServerAliveInterval=15 -o ServerAliveCountMax=4',
            $cmd,
        );
        self::assertStringContainsString("-i '/home/my user/.ssh/k'", SshCommand::build('/home/my user/.ssh/k', '/k'));
        self::assertStringNotContainsString('accept-new', $cmd);
    }

    /**
     * @covers-req GIT-06
     */
    public function testErrorClassification(): void
    {
        $cases = [
            ["git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.", ErrorCode::GIT_AUTH],
            ["ERROR: Repository not found.\nfatal: Could not read from remote repository.", ErrorCode::GIT_AUTH],
            ["Host key verification failed.\nfatal: Could not read from remote repository.", ErrorCode::GIT_HOSTKEY],
            ['ssh: connect to host github.com port 22: Connection timed out', ErrorCode::GIT_NET],
            ['ssh: connect to host github.com port 22: Connection refused', ErrorCode::GIT_NET],
            ['ssh: Could not resolve hostname github.com: Name or service not known', ErrorCode::GIT_NET],
            ['ssh: connect to host github.com port 22: Network is unreachable', ErrorCode::GIT_NET],
            ['fatal: something else entirely', ErrorCode::GIT],
        ];
        foreach ($cases as [$stderr, $code]) {
            $e = GitRepository::classify(new ProcessResult(['git'], 128, '', $stderr, 1.0), 'acme/shop');
            self::assertSame($code, $e->errorCode, $stderr);
        }
        $auth = GitRepository::classify(new ProcessResult(['git'], 128, '', 'Permission denied (publickey)', 1.0), 'acme/shop');
        self::assertSame('GitHub refused the deploy key for acme/shop', $auth->getMessage());
        self::assertSame(3, $auth->exitCode());
        self::assertStringContainsString('something else', GitRepository::classify(new ProcessResult(['git'], 1, '', 'fatal: something else', 1.0), 'x')->getMessage());
    }

    /**
     * @covers-req GIT-16
     */
    public function testCorruptionDetection(): void
    {
        self::assertTrue(GitRepository::isCorruption('error: refs/heads/main does not point to a valid object! fatal: bad object HEAD'));
        self::assertTrue(GitRepository::isCorruption('error: object file ./objects/ab/cdef is corrupt'));
        self::assertTrue(GitRepository::isCorruption("fatal: '/x/repo.git' does not appear to be a git repository"));
        self::assertFalse(GitRepository::isCorruption('Permission denied (publickey)'));
    }
}
