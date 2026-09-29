<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

final class GitHubRepo
{
    public function __construct(
        public readonly string $fullName,
        public readonly bool $private,
        public readonly string $defaultBranch,
        public readonly ?string $updatedAt,
    ) {
    }
}
