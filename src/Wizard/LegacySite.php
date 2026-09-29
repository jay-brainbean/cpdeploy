<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Git\RepoUrl;

/**
 * A site set up by the old cpanel-git-setup.sh (LEG-01, LEG-02).
 */
final class LegacySite
{
    /**
     * @param array<string, string> $conf deploy.conf values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly ?RepoUrl $repo,
        public readonly array $conf,
        public readonly string $keyPath,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->conf[$key] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }
}
