<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

/**
 * One commit (GIT-11).
 */
final class Commit
{
    public function __construct(
        public readonly string $sha,
        public readonly string $short,
        public readonly string $author,
        public readonly string $email,
        public readonly int $timestamp,
        public readonly string $subject,
    ) {
    }
}
