<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Git\Commit;

/**
 * What changes between the live commit L and the target T (CHG-02).
 */
final class ChangeSet
{
    /**
     * @param list<Commit> $commits up to 50, newest first
     * @param array{added: array<string, string>, removed: array<string, string>, updated: array<string, array{0: string, 1: string}>} $lockDiff
     * @param list<string> $migrationsAdded migration names (file name without .php)
     * @param list<string> $migrationsModified
     * @param list<string> $migrationsDeleted
     * @param list<string> $changedPaths every changed path (empty on a first deploy)
     */
    public function __construct(
        public readonly ?string $liveCommit,
        public readonly string $targetCommit,
        public readonly array $commits,
        public readonly int $commitCount,
        public readonly int $behindCount,
        public readonly bool $isRewind,
        public readonly bool $sameCommit,
        public readonly bool $composerChanged,
        public readonly array $lockDiff,
        public readonly array $migrationsAdded,
        public readonly array $migrationsModified,
        public readonly array $migrationsDeleted,
        public readonly bool $nodeChanged,
        public readonly bool $phpRequirementChanged,
        public readonly bool $htaccessChanged,
        public readonly array $changedPaths,
    ) {
    }

    public function firstDeploy(): bool
    {
        return $this->liveCommit === null;
    }

    /**
     * PLN-05: the frontend build default is Yes when Node files changed, or any
     * file outside vendor/, storage/, database/ and tests/.
     */
    public function frontendRelevant(): bool
    {
        if ($this->firstDeploy() || $this->nodeChanged) {
            return true;
        }
        foreach ($this->changedPaths as $path) {
            if (preg_match('#^(vendor|storage|database|tests)/#', $path) !== 1) {
                return true;
            }
        }

        return false;
    }
}
