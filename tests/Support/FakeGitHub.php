<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

/**
 * A running fake GitHub API (tests/Support/FakeGitHub/router.php) with its state.
 */
final class FakeGitHub
{
    public readonly LocalServer $server;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(private readonly string $dir, array $state = [])
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $state += [
            'tokens' => ['github_pat_good_token_123456' => ['login' => 'jay', 'expires' => '2027-01-01 00:00:00 UTC']],
            'repos' => [
                ['full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'main', 'updated_at' => '2026-09-27T10:00:00Z', 'branches' => ['main', 'develop']],
            ],
            'keys' => [],
            'noAdmin' => [],
            'nextId' => 1000,
            'meta_ssh_keys' => [],
        ];
        file_put_contents($dir . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->server = new LocalServer($dir, __DIR__ . '/FakeGitHub/router.php');
    }

    public function url(): string
    {
        return $this->server->url();
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $data = json_decode((string) file_get_contents($this->dir . '/state.json'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(): array
    {
        $file = $this->dir . '/calls.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function stop(): void
    {
        $this->server->stop();
    }
}
