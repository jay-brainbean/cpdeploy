<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Http;
use Cpdeploy\Support\HttpResponse;
use Cpdeploy\Support\Masker;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The GitHub REST calls cpdeploy makes (GH-01, GH-02). The token travels in
 * curl's stdin config, never on the command line (SEC-03). Nothing is written to
 * GitHub except this site's deploy keys (INV-04).
 */
final class GitHubApi
{
    public const DEFAULT_BASE = 'https://api.github.com';
    public const API_VERSION = '2022-11-28';

    /** Stop following `Link: rel="next"` after this many pages. */
    private const MAX_PAGES = 20;

    public function __construct(
        private readonly Http $http,
        private readonly Masker $masker,
        private readonly string $base,
        private readonly ?string $token,
        private readonly float $timeout = 30,
    ) {
        $this->masker->add($token);
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * GET /user: validates the token; reads the expiry header (GH-05).
     */
    public function user(): GitHubUser
    {
        $response = $this->get('/user');
        $data = $this->json($response);
        $login = is_string($data['login'] ?? null) ? $data['login'] : '?';
        $expires = null;
        $header = $response->header('github-authentication-token-expiration');
        if ($header !== null) {
            try {
                $expires = new DateTimeImmutable($header, new DateTimeZone('UTC'));
            } catch (\Exception) {
                $expires = null;
            }
        }

        return new GitHubUser($login, $expires, self::isClassic((string) $this->token));
    }

    /**
     * GET /user/repos, all pages (repo picker).
     *
     * @return list<GitHubRepo>
     */
    public function repos(): array
    {
        $out = [];
        foreach ($this->pages('/user/repos?per_page=100&sort=updated') as $row) {
            $repo = self::toRepo($row);
            if ($repo !== null) {
                $out[] = $repo;
            }
        }

        return $out;
    }

    public function repo(string $owner, string $name): GitHubRepo
    {
        $repo = self::toRepo($this->json($this->get("/repos/{$owner}/{$name}", "{$owner}/{$name}")));
        if ($repo === null) {
            throw new CpdeployException(ErrorCode::GITHUB_DOWN, 'GitHub API unavailable (unexpected answer)', 'Try later, or use the manual key flow.');
        }

        return $repo;
    }

    /**
     * @return list<string>
     */
    public function branches(string $owner, string $name): array
    {
        $out = [];
        foreach ($this->pages("/repos/{$owner}/{$name}/branches?per_page=100", "{$owner}/{$name}") as $row) {
            if (is_string($row['name'] ?? null)) {
                $out[] = $row['name'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: int, title: string, key: string, read_only: bool}>
     */
    public function keys(string $owner, string $name): array
    {
        $out = [];
        foreach ($this->pages("/repos/{$owner}/{$name}/keys?per_page=100", "{$owner}/{$name}", true) as $row) {
            if (is_int($row['id'] ?? null)) {
                $out[] = [
                    'id' => $row['id'],
                    'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                    'key' => is_string($row['key'] ?? null) ? $row['key'] : '',
                    'read_only' => ($row['read_only'] ?? true) === true,
                ];
            }
        }

        return $out;
    }

    /**
     * POST /repos/{o}/{r}/keys, always read-only (SEC-05). Returns the key id.
     *
     * @throws KeyInUseException on 422
     */
    public function addKey(string $owner, string $name, string $title, string $publicKey): int
    {
        $body = (string) json_encode(['title' => $title, 'key' => trim($publicKey), 'read_only' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->http->send('POST', $this->base . "/repos/{$owner}/{$name}/keys", $body, $this->headers() + ['Content-Type' => 'application/json'], $this->auth(), $this->timeout);
        if ($response->status === 422) {
            throw new KeyInUseException('GitHub says this key is already in use');
        }
        $this->check($response, "{$owner}/{$name}", true);
        $data = $this->json($response);
        if (!is_int($data['id'] ?? null)) {
            throw new CpdeployException(ErrorCode::GITHUB_DOWN, 'GitHub API unavailable (no key id returned)', 'Try later, or use the manual key flow.');
        }

        return $data['id'];
    }

    /**
     * DELETE /repos/{o}/{r}/keys/{id}. A key that's already gone (404) is fine (GIT-19).
     */
    public function deleteKey(string $owner, string $name, int $id): void
    {
        $response = $this->http->send('DELETE', $this->base . "/repos/{$owner}/{$name}/keys/{$id}", null, $this->headers(), $this->auth(), $this->timeout);
        if ($response->status === 404) {
            return;
        }
        $this->check($response, "{$owner}/{$name}", true);
    }

    /**
     * GET /meta (no auth): GitHub's SSH host keys, for --refresh-host-keys (GIT-03).
     *
     * @return list<string> "type base64" lines
     */
    public function sshKeys(): array
    {
        $response = $this->http->get($this->base . '/meta', $this->headers(), [], $this->timeout);
        $this->check($response, null, false);
        $data = $this->json($response);

        return is_array($data['ssh_keys'] ?? null) ? array_values(array_filter($data['ssh_keys'], 'is_string')) : [];
    }

    /**
     * UPD-01: the newest releases of the tool's repository (first page, newest
     * first); the stored token is sent when there is one (a private tool repo).
     *
     * @return list<array<string, mixed>>
     */
    public function releases(string $owner, string $name): array
    {
        $rows = $this->json($this->get("/repos/{$owner}/{$name}/releases?per_page=30", "{$owner}/{$name}"));
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['tag_name'] ?? null)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * UPD-02: a release asset through its API URL (works for private repos).
     */
    public function downloadAsset(string $url, string $file, float $timeout = 600): void
    {
        $response = $this->http->get($url, ['Accept' => 'application/octet-stream', 'X-GitHub-Api-Version' => self::API_VERSION], $this->auth(), $timeout, saveTo: $file);
        if (!$response->ok()) {
            @unlink($file);
            $this->check($response, null, false);
        }
    }

    public static function isClassic(string $token): bool
    {
        return str_starts_with($token, 'ghp_');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return $this->hasToken() ? ['Authorization' => 'Bearer ' . $this->token] : [];
    }

    private function get(string $path, ?string $repo = null, bool $keys = false): HttpResponse
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $response = $this->http->get($url, $this->headers(), $this->auth(), $this->timeout);
        $this->check($response, $repo, $keys);

        return $response;
    }

    /**
     * Follows `Link: <…>; rel="next"`.
     *
     * @return list<array<mixed>>
     */
    private function pages(string $path, ?string $repo = null, bool $keys = false): array
    {
        $rows = [];
        $url = $this->base . $path;
        for ($page = 0; $url !== null && $page < self::MAX_PAGES; $page++) {
            $response = $this->get($url, $repo, $keys);
            foreach ($this->json($response) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $url = self::nextLink($response->header('link'));
        }

        return $rows;
    }

    public static function nextLink(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }
        foreach (explode(',', $link) as $part) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $part, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * GH-04 error mapping.
     */
    private function check(HttpResponse $response, ?string $repo, bool $keys): void
    {
        if ($response->ok()) {
            return;
        }
        $status = $response->status;
        if ($status === 401) {
            throw new CpdeployException(ErrorCode::TOKEN_INVALID, 'The GitHub token is invalid or expired', 'Settings → GitHub token (or: cpdeploy token set).');
        }
        if ($status === 403 && $response->header('x-ratelimit-remaining') === '0') {
            $reset = $response->header('x-ratelimit-reset');
            $when = is_numeric($reset) ? (new DateTimeImmutable('@' . $reset))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i') : 'soon';
            throw new CpdeployException(ErrorCode::GITHUB_RATE, "GitHub API rate limit reached (resets {$when})", 'Try later, or use the manual key flow.');
        }
        if (($status === 403 || $status === 404) && $repo !== null) {
            throw new CpdeployException(
                ErrorCode::TOKEN_PERMS,
                $keys ? "The token can't manage deploy keys for {$repo}" : "The token can't see {$repo}",
                $keys ? 'Give it Administration: Read and write on this repo, or add the key manually.' : 'Give the token access to this repository (Repository access), or add the key manually.',
            );
        }
        if ($status === 0 || $status >= 500) {
            throw new CpdeployException(ErrorCode::GITHUB_DOWN, 'GitHub API unavailable' . ($status > 0 ? " (HTTP {$status})" : ''), 'Try later, or use the manual key flow.');
        }
        $message = $this->json($response)['message'] ?? null;
        throw new CpdeployException(
            ErrorCode::GITHUB_DOWN,
            sprintf('GitHub API refused the request (HTTP %d%s)', $status, is_string($message) ? ': ' . $this->masker->mask($message) : ''),
            'Try later, or use the manual key flow.',
        );
    }

    /**
     * @return array<mixed>
     */
    private function json(HttpResponse $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed> $row
     */
    private static function toRepo(array $row): ?GitHubRepo
    {
        if (!is_string($row['full_name'] ?? null)) {
            return null;
        }

        return new GitHubRepo(
            $row['full_name'],
            ($row['private'] ?? false) === true,
            is_string($row['default_branch'] ?? null) ? $row['default_branch'] : 'main',
            is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        );
    }
}
