<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Closure;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * GIT-03 `check --refresh-host-keys` and Settings → Refresh GitHub host keys:
 * GitHub's current keys from GET /meta, their SHA256 fingerprints for the user
 * to compare, then ~/cpdeploy/known_hosts replaced.
 */
final class HostKeyRefresh
{
    /**
     * @param Closure(): GitHubApi $api
     */
    public function __construct(
        private readonly HostKeys $hostKeys,
        private readonly Closure $api,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The new known_hosts text and, per key type, [fingerprint, as published].
     *
     * @return array{0: string, 1: array<string, array{0: string, 1: bool}>}
     */
    public function fetch(): array
    {
        $text = HostKeys::fromMeta(($this->api)()->sshKeys(), $this->clock->iso());
        $found = [];
        foreach (HostKeys::parse($text) as [, $type, $fingerprint]) {
            $found[$type] = [$fingerprint, (HostKeys::PUBLISHED[$type] ?? null) === $fingerprint];
        }
        if ($found === []) {
            throw new CpdeployException(ErrorCode::GITHUB_DOWN, 'GitHub returned no SSH host keys', 'Try again later; nothing was changed.');
        }

        return [$text, $found];
    }

    public function apply(string $text): void
    {
        $this->hostKeys->write($text);
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $found
     * @return list<string>
     */
    public static function lines(array $found): array
    {
        $lines = ["GitHub's SSH host keys (from https://api.github.com/meta):"];
        foreach ($found as $type => [$fingerprint, $published]) {
            $lines[] = sprintf('  %-22s %s  %s', $type, $fingerprint, $published ? '(as published)' : '(new)');
        }
        $lines[] = 'Compare them with https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints';

        return $lines;
    }
}
