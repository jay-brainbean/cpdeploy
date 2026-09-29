<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * GitHub's SSH host keys (GIT-03). cpdeploy trusts only these keys, written to
 * ~/cpdeploy/known_hosts from resources/github_known_hosts; never
 * StrictHostKeyChecking=accept-new (SEC-04).
 */
final class HostKeys
{
    /** GitHub's published SHA256 fingerprints (Appendix B.6). */
    public const PUBLISHED = [
        'ssh-rsa' => 'SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s',
        'ecdsa-sha2-nistp256' => 'SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM',
        'ssh-ed25519' => 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU',
    ];

    public const HOSTS = ['github.com', '[ssh.github.com]:443'];

    public function __construct(
        private readonly Fs $fs,
        private readonly string $installed,
        private readonly string $embeddedFile,
    ) {
    }

    public static function embeddedPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/github_known_hosts';
    }

    public function embedded(): string
    {
        $text = @file_get_contents($this->embeddedFile);
        if ($text === false || $text === '') {
            throw new RuntimeException('resources/github_known_hosts is missing from this build');
        }

        return $text;
    }

    /**
     * Writes ~/cpdeploy/known_hosts (644) when it is missing or differs from
     * the embedded copy.
     */
    public function install(): void
    {
        $embedded = $this->embedded();
        if (@file_get_contents($this->installed) !== $embedded) {
            $this->fs->writeAtomic($this->installed, $embedded, Paths::MODE_PUBLIC_FILE);
        }
    }

    public function installedMatches(): bool
    {
        return @file_get_contents($this->installed) === $this->embedded();
    }

    /**
     * Problems with the embedded keys: every published fingerprint must be present
     * for every host, and nothing else.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $found = [];
        foreach (self::parse($this->embedded()) as [$host, $type, $fingerprint]) {
            $found[$host][$type] = $fingerprint;
        }
        $problems = [];
        foreach (self::HOSTS as $host) {
            foreach (self::PUBLISHED as $type => $fingerprint) {
                $actual = $found[$host][$type] ?? null;
                if ($actual !== $fingerprint) {
                    $problems[] = sprintf('%s %s: expected %s, found %s', $host, $type, $fingerprint, $actual ?? 'none');
                }
            }
            foreach (array_keys($found[$host] ?? []) as $type) {
                if (!isset(self::PUBLISHED[$type])) {
                    $problems[] = "{$host} {$type}: not a published GitHub key";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [host, key type, SHA256 fingerprint]
     */
    public static function parse(string $knownHosts): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $knownHosts) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3) {
                continue;
            }
            $out[] = [$parts[0], $parts[1], self::fingerprint($parts[2])];
        }

        return $out;
    }

    /**
     * OpenSSH's SHA256 fingerprint of a base64 public key blob.
     */
    public static function fingerprint(string $base64Key): string
    {
        $blob = base64_decode($base64Key, true);
        if ($blob === false) {
            return 'invalid';
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
