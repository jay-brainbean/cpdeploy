<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\GitHub;

use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\GitHub\KeyInUseException;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Tests\Support\TestCase;

final class GitHubApiTest extends TestCase
{
    private const TOKEN = 'github_pat_good_token_123456';

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();
        $repos = [];
        for ($i = 1; $i <= 150; $i++) {
            $repos[] = ['full_name' => "acme/repo{$i}", 'private' => $i % 2 === 0, 'default_branch' => 'main', 'updated_at' => '2026-09-01T00:00:00Z', 'branches' => ['main']];
        }
        $repos[] = ['full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'production', 'updated_at' => '2026-09-27T10:00:00Z', 'branches' => ['production', 'develop']];
        $repos[] = ['full_name' => 'acme/secret', 'private' => true, 'default_branch' => 'main', 'updated_at' => null, 'branches' => ['main']];
        $this->github = new FakeGitHub($this->tmp . '/github', [
            'repos' => $repos,
            'noAdmin' => ['acme/secret'],
            'tokens' => [
                self::TOKEN => ['login' => 'jay', 'expires' => '2026-10-05 12:00:00 UTC'],
                'ghp_classictoken1234567890' => ['login' => 'jay'],
            ],
        ]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
    }

    protected function tearDown(): void
    {
        $this->github->stop();
        parent::tearDown();
    }

    private function api(?string $token = self::TOKEN): GitHubApi
    {
        return $this->services()->github($token);
    }

    /**
     * @covers-req GH-01
     * @covers-req GH-05
     */
    public function testUserAndHeaders(): void
    {
        $user = $this->api()->user();

        self::assertSame('jay', $user->login);
        self::assertSame('2026-10-05T12:00:00+00:00', $user->expiresAt?->format(DATE_ATOM));
        self::assertFalse($user->classic);
        self::assertTrue($user->expiresWithin(new \DateTimeImmutable('2026-09-29T00:00:00Z')));
        self::assertFalse($user->expiresWithin(new \DateTimeImmutable('2026-09-01T00:00:00Z')));

        $call = $this->github->calls()[0];
        self::assertSame('Bearer ' . self::TOKEN, $call['auth']);
        self::assertSame('application/vnd.github+json', $call['accept']);
        self::assertSame('2022-11-28', $call['api_version']);
        self::assertStringStartsWith('cpdeploy/', (string) $call['user_agent']);

        $classic = $this->api('ghp_classictoken1234567890')->user();
        self::assertTrue($classic->classic);
        self::assertNull($classic->expiresAt);
    }

    /**
     * @covers-req GH-02
     */
    public function testReposFollowPaginationAndBranches(): void
    {
        $repos = $this->api()->repos();

        self::assertCount(152, $repos);
        self::assertSame(2, count(array_filter($this->github->calls(), static fn ($c) => $c['path'] === '/user/repos')));
        $shop = $this->api()->repo('acme', 'shop');
        self::assertSame('production', $shop->defaultBranch);
        self::assertTrue($shop->private);
        self::assertSame(['production', 'develop'], $this->api()->branches('acme', 'shop'));
    }

    /**
     * @covers-req GH-02
     * @covers-req SEC-05
     * @covers-req INV-04
     */
    public function testAddListAndDeleteReadOnlyKeys(): void
    {
        $api = $this->api();
        $id = $api->addKey('acme', 'shop', 'cpdeploy · shop · u@h', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKeyOne cpdeploy:shop@h\n");

        self::assertSame(1000, $id);
        $keys = $api->keys('acme', 'shop');
        self::assertSame([['id' => 1000, 'title' => 'cpdeploy · shop · u@h', 'key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKeyOne', 'read_only' => true]], $keys);
        $post = array_values(array_filter($this->github->calls(), static fn ($c) => $c['method'] === 'POST'))[0];
        self::assertTrue($post['body']['read_only']);

        try {
            $api->addKey('acme', 'shop', 'again', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKeyOne other-comment');
            self::fail('No exception');
        } catch (KeyInUseException) {
        }

        $api->deleteKey('acme', 'shop', 1000);
        self::assertSame([], $api->keys('acme', 'shop'));
        $api->deleteKey('acme', 'shop', 1000);

        $writes = array_filter($this->github->calls(), static fn ($c) => $c['method'] !== 'GET');
        foreach ($writes as $call) {
            self::assertStringStartsWith('/repos/acme/shop/keys', $call['path'], 'Only deploy keys are ever written');
        }
    }

    /**
     * @covers-req GH-04
     */
    public function testErrorMapping(): void
    {
        $cases = [
            ['expired', static fn (GitHubApi $a) => $a->user(), ErrorCode::TOKEN_INVALID],
            ['not-a-known-token-at-all', static fn (GitHubApi $a) => $a->repos(), ErrorCode::TOKEN_INVALID],
            ['ratelimited', static fn (GitHubApi $a) => $a->user(), ErrorCode::GITHUB_RATE],
            ['boom', static fn (GitHubApi $a) => $a->user(), ErrorCode::GITHUB_DOWN],
            [self::TOKEN, static fn (GitHubApi $a) => $a->addKey('acme', 'secret', 't', 'ssh-ed25519 AAAAX'), ErrorCode::TOKEN_PERMS],
            [self::TOKEN, static fn (GitHubApi $a) => $a->keys('acme', 'nope'), ErrorCode::TOKEN_PERMS],
            [self::TOKEN, static fn (GitHubApi $a) => $a->repo('acme', 'nope'), ErrorCode::TOKEN_PERMS],
        ];
        foreach ($cases as [$token, $call, $code]) {
            try {
                $call($this->api($token));
                self::fail("No exception for {$code->value}");
            } catch (CpdeployException $e) {
                self::assertSame($code, $e->errorCode, $e->getMessage());
            }
        }

        try {
            $this->api('ratelimited')->user();
        } catch (CpdeployException $e) {
            self::assertMatchesRegularExpression('/resets \d\d:\d\d/', $e->getMessage());
        }
        try {
            $this->api()->addKey('acme', 'secret', 't', 'ssh-ed25519 AAAAX');
        } catch (CpdeployException $e) {
            self::assertSame("The token can't manage deploy keys for acme/secret", $e->getMessage());
            self::assertStringContainsString('Administration: Read and write', $e->hint);
        }
    }

    public function testNetworkFailureIsGithubDown(): void
    {
        $this->github->stop();

        $this->expectExceptionObject(new CpdeployException(ErrorCode::GITHUB_DOWN, 'GitHub API unavailable'));
        $this->api()->user();
    }

    /**
     * @covers-req LOG-04
     */
    public function testTokenIsMaskedEverywhere(): void
    {
        $services = $this->services();
        $services->github(self::TOKEN);

        self::assertSame('token ' . \Cpdeploy\Support\Masker::MASK, $services->masker()->mask('token ' . self::TOKEN));
    }

    public function testNextLink(): void
    {
        self::assertSame('https://api.github.com/user/repos?page=2', GitHubApi::nextLink('<https://api.github.com/user/repos?page=2>; rel="next", <https://api.github.com/user/repos?page=5>; rel="last"'));
        self::assertNull(GitHubApi::nextLink('<https://x/?page=1>; rel="prev"'));
        self::assertNull(GitHubApi::nextLink(null));
    }
}
