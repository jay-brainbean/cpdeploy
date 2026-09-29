<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Git;

use Cpdeploy\Git\ManualKeyInstructions;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Tests\Support\TestCase;

final class DeployKeyServiceTest extends TestCase
{
    private const TOKEN = 'github_pat_good_token_123456';

    private FakeGitHub $github;
    private RepoUrl $repo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/usr/bin/ssh-keygen') && trim((string) shell_exec('command -v ssh-keygen')) === '') {
            self::markTestSkipped('ssh-keygen is not installed');
        }
        $this->github = new FakeGitHub($this->tmp . '/github', ['noAdmin' => ['acme/locked'], 'repos' => [
            ['full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'main', 'updated_at' => null, 'branches' => ['main']],
            ['full_name' => 'acme/locked', 'private' => true, 'default_branch' => 'main', 'updated_at' => null, 'branches' => ['main']],
        ]]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
        $this->repo = RepoUrl::parse('acme/shop');

        // A local "GitHub" repository; file:// ignores the SSH key, so access tests
        // flip between a working and a missing remote.
        $work = $this->tmp . '/work';
        exec('git init -q -b main ' . escapeshellarg($work) . ' && git -C ' . escapeshellarg($work) . ' -c user.name=D -c user.email=d@e commit -q --allow-empty -m init && git clone -q --bare ' . escapeshellarg($work) . ' ' . escapeshellarg($this->tmp . '/remote.git'));
        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = 'file://' . $this->tmp . '/remote.git';
        mkdir($this->root, 0711, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->github)) {
            $this->github->stop();
        }
        parent::tearDown();
    }

    /**
     * @covers-req GIT-05
     * @covers-req PERM-01
     */
    public function testGenerateCreatesAnEd25519KeyWithModes(): void
    {
        $keys = $this->services()->deployKeys();
        $path = $keys->generate('shop', 'server1');

        self::assertSame($this->home . '/.ssh/cpdeploy_shop', $path);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0644, fileperms($path . '.pub') & 0777);
        self::assertSame(0700, fileperms($this->home . '/.ssh') & 0777);
        self::assertMatchesRegularExpression('/^ssh-ed25519 \S+ cpdeploy:shop@server1$/', $keys->publicKey('shop'));
        self::assertStringStartsWith('SHA256:', (string) $keys->fingerprint('shop'));
        self::assertTrue($keys->exists('shop'));

        $this->expectExceptionMessage('A key already exists');
        $keys->generate('shop', 'server1');
    }

    /**
     * @covers-req GIT-17
     */
    public function testRegisterWithTokenAddsAReadOnlyKey(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');

        $id = $keys->register($this->services()->github(self::TOKEN), $this->repo, 'shop', 'brainbean', 'server1');

        $stored = $this->github->state()['keys']['acme/shop'][0];
        self::assertSame($id, $stored['id']);
        self::assertSame('cpdeploy · shop · brainbean@server1', $stored['title']);
        self::assertTrue($stored['read_only']);
        self::assertStringStartsWith($stored['key'], $keys->publicKey('shop'));
    }

    /**
     * @covers-req GIT-17
     */
    public function testKeyInUseRetryUsesANewKey(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');
        $old = $keys->publicKey('shop');
        $api = $this->services()->github(self::TOKEN);
        $api->addKey('acme', 'shop', 'someone else', $old);

        $keys->register($api, $this->repo, 'shop', 'brainbean', 'server1');

        self::assertNotSame($old, $keys->publicKey('shop'));
        self::assertCount(2, $this->github->state()['keys']['acme/shop']);
    }

    /**
     * @covers-req GIT-17
     */
    public function testMissingAdminPermissionIsETokenPerms(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');

        try {
            $keys->register($this->services()->github(self::TOKEN), RepoUrl::parse('acme/locked'), 'shop', 'u', 'h');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::TOKEN_PERMS, $e->errorCode);
        }
        $manual = $keys->instructions(RepoUrl::parse('acme/locked'), 'shop', 'u', 'h');
        self::assertSame('https://github.com/acme/locked/settings/keys/new', $manual->url);
        self::assertSame([
            'Add this deploy key to acme/locked:',
            '  https://github.com/acme/locked/settings/keys/new',
            '  Title:  cpdeploy · shop · u@h',
            '  Key:    ' . $keys->publicKey('shop'),
            '  Leave "Allow write access" unchecked.',
        ], $manual->lines('acme/locked'));
    }

    /**
     * @covers-req GIT-06
     */
    public function testAccessTest(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');

        self::assertSame(['main'], array_keys($keys->test($this->repo, 'shop', 'ssh22')));

        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = 'file://' . $this->tmp . '/missing.git';
        try {
            $this->services()->deployKeys()->test($this->repo, 'shop', 'ssh22');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_AUTH, $e->errorCode);
        }
    }

    /**
     * S-32 with a token: new key registered and tested, old key removed, id updated.
     *
     * @covers-req GIT-18
     */
    public function testRotationWithToken(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');
        $api = $this->services()->github(self::TOKEN);
        $oldId = $keys->register($api, $this->repo, 'shop', 'u', 'server1');
        $oldPub = $keys->publicKey('shop');

        $result = $keys->rotate($this->repo, 'shop', 'ssh22', 'u', 'server1', $api, $oldId, static fn (): bool => false, '2026-09-29');

        self::assertNotNull($result->keyId);
        self::assertNotSame($oldId, $result->keyId);
        self::assertNull($result->manualDelete);
        self::assertNotSame($oldPub, $keys->publicKey('shop'));
        self::assertFileDoesNotExist($this->home . '/.ssh/cpdeploy_shop.new');
        $remaining = $this->github->state()['keys']['acme/shop'];
        self::assertSame([$result->keyId], array_column($remaining, 'id'), 'Old key deleted on GitHub');
        self::assertSame('cpdeploy · shop · u@server1 · 2026-09-29', $remaining[0]['title']);
    }

    /**
     * S-32: a failed test leaves the old key in place and removes the new one from GitHub.
     *
     * @covers-req GIT-18
     */
    public function testFailedRotationKeepsTheOldKey(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');
        $api = $this->services()->github(self::TOKEN);
        $oldId = $keys->register($api, $this->repo, 'shop', 'u', 'server1');
        $oldPub = $keys->publicKey('shop');
        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = 'file://' . $this->tmp . '/missing.git';

        try {
            $this->services()->deployKeys()->rotate($this->repo, 'shop', 'ssh22', 'u', 'server1', $api, $oldId, static fn (): bool => false, '2026-09-29');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_AUTH, $e->errorCode);
        }
        self::assertSame($oldPub, $keys->publicKey('shop'));
        self::assertFileDoesNotExist($this->home . '/.ssh/cpdeploy_shop.new');
        self::assertSame([$oldId], array_column($this->github->state()['keys']['acme/shop'], 'id'));
    }

    /**
     * @covers-req GIT-18
     */
    public function testManualRotation(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');
        $shown = null;

        $result = $keys->rotate($this->repo, 'shop', 'ssh22', 'u', 'server1', null, null, static function (ManualKeyInstructions $i) use (&$shown): bool {
            $shown = $i;

            return true;
        }, '2026-09-29');

        self::assertInstanceOf(ManualKeyInstructions::class, $shown);
        self::assertSame('cpdeploy · shop · u@server1 · 2026-09-29', $shown->title);
        self::assertSame($shown->publicKey, $keys->publicKey('shop'));
        self::assertNull($result->keyId);
        self::assertSame('Delete the old key "cpdeploy · shop · u@server1" under https://github.com/acme/shop/settings/keys', $result->manualDelete);

        // Cancelling keeps the current key.
        $current = $keys->publicKey('shop');
        try {
            $keys->rotate($this->repo, 'shop', 'ssh22', 'u', 'server1', null, null, static fn (): bool => false, '2026-09-30');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CANCELLED, $e->errorCode);
        }
        self::assertSame($current, $keys->publicKey('shop'));
    }

    /**
     * @covers-req GIT-19
     */
    public function testRemove(): void
    {
        $keys = $this->services()->deployKeys();
        $keys->generate('shop', 'server1');
        $api = $this->services()->github(self::TOKEN);
        $id = $keys->register($api, $this->repo, 'shop', 'u', 'server1');

        self::assertNull($keys->remove($this->repo, 'shop', 'u', 'server1', $api, $id, true));
        self::assertSame([], $this->github->state()['keys']['acme/shop']);
        self::assertFalse($keys->exists('shop'));
        self::assertNull($keys->remove($this->repo, 'shop', 'u', 'server1', $api, $id, false), '404 is not an error');
        self::assertStringContainsString('Delete the deploy key "cpdeploy · shop · u@server1"', (string) $keys->remove($this->repo, 'shop', 'u', 'server1', null, null, false));
    }
}
