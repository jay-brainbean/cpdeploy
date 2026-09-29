<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

use DateTimeImmutable;

/**
 * Who a token belongs to and when it expires (GET /user).
 */
final class GitHubUser
{
    public function __construct(
        public readonly string $login,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly bool $classic,
    ) {
    }

    /**
     * GH-05: true when the token expires in under $days days.
     */
    public function expiresWithin(DateTimeImmutable $now, int $days = 14): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->getTimestamp() - $now->getTimestamp() < $days * 86400;
    }
}
