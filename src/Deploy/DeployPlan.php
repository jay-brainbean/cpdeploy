<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * Every Phase A answer plus its reason (PLN-06), e.g. "composer: install (lock
 * changed)". Nothing is asked after the final confirmation (PLN-01) except the
 * late pending-migration prompt of PLN-07.
 */
final class DeployPlan
{
    public const COMPOSER_NONE = 'none';
    public const COMPOSER_INSTALL = 'install';
    public const COMPOSER_REUSE = 'reuse';

    public const BUILD_NONE = 'none';
    public const BUILD_RUN = 'build';
    public const BUILD_REUSE = 'reuse';

    /** Run migrations (answered Yes, a flag, or a first deploy). */
    public const MIGRATE_YES = 'yes';
    /** The user answered Skip (or --migrate=no). */
    public const MIGRATE_NO = 'no';
    /** steps.migrate: every — run if the post-build check (B9) finds pending ones. */
    public const MIGRATE_AUTO = 'auto';
    /** No question was shown: nothing new was detected (PLN-07 applies). */
    public const MIGRATE_NONE = 'none';

    /**
     * @param array<int, bool> $custom custom command index → run
     * @param list<string> $migrationsExpected names shown in the question
     */
    public function __construct(
        public readonly string $composer,
        public readonly string $composerReason,
        public readonly string $build,
        public readonly string $buildReason,
        public readonly string $migrate,
        public readonly string $migrateReason,
        public readonly bool $seed,
        public readonly string $seedReason,
        public readonly array $custom,
        public readonly bool $optimize,
        public readonly bool $storageLink,
        public readonly bool $healthCheck,
        public readonly array $migrationsExpected = [],
    ) {
    }

    /**
     * Whether a migration run is possible at all (for the database check, PRE-14).
     */
    public function mayMigrate(): bool
    {
        return in_array($this->migrate, [self::MIGRATE_YES, self::MIGRATE_AUTO], true);
    }

    public function runsCustom(int $index): bool
    {
        return $this->custom[$index] ?? false;
    }

    /**
     * The reasons, for the log and the history notes.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];
        if ($this->composer !== self::COMPOSER_NONE) {
            $notes[] = sprintf('composer: %s (%s)', $this->composer === self::COMPOSER_INSTALL ? 'install' : 'reuse vendor/', $this->composerReason);
        }
        if ($this->build !== self::BUILD_NONE) {
            $notes[] = sprintf('frontend: %s (%s)', $this->build === self::BUILD_RUN ? 'build' : 'reuse build output', $this->buildReason);
        }
        if ($this->migrate !== self::MIGRATE_NONE) {
            $notes[] = sprintf('migrations: %s (%s)', $this->migrate, $this->migrateReason);
        }
        if ($this->seed) {
            $notes[] = 'seed: yes (' . $this->seedReason . ')';
        }

        return $notes;
    }
}
