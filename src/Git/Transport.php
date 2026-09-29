<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\TcpProbe;

/**
 * Chooses how to reach GitHub over SSH (GIT-04): github.com:22, else
 * ssh.github.com:443. Also builds remote URLs, honouring the test-only
 * CPDEPLOY_GIT_URL_OVERRIDE (GIT-01).
 */
final class Transport
{
    public const PORT22 = 'github.com:22';
    public const PORT443 = 'ssh.github.com:443';

    public function __construct(
        private readonly TcpProbe $probe,
        private readonly ?string $urlOverride = null,
    ) {
    }

    /**
     * ssh22 when port 22 answers within 5 s, else ssh443, else E_GIT_NET.
     */
    public function detect(): string
    {
        $ok = $this->probe->reachable([self::PORT22, self::PORT443], 5.0);
        if ($ok[self::PORT22]) {
            return RepoUrl::TRANSPORT_SSH22;
        }
        if ($ok[self::PORT443]) {
            return RepoUrl::TRANSPORT_SSH443;
        }
        throw new CpdeployException(ErrorCode::GIT_NET, "Can't reach GitHub on port 22 or 443", 'Ask your host to allow outbound SSH to github.com.');
    }

    public function url(RepoUrl $repo, string $transport): string
    {
        return $this->urlOverride ?? $repo->remote($transport);
    }

    /**
     * GIT-04: a connection error (not an auth error) is worth one re-detect and retry.
     */
    public static function worthRetrying(CpdeployException $e): bool
    {
        return $e->errorCode === ErrorCode::GIT_NET;
    }
}
