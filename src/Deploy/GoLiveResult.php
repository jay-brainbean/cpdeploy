<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * Outcome of Phase C when the new release went live.
 */
final class GoLiveResult
{
    /**
     * @param list<string> $migrationsRan
     */
    public function __construct(
        public readonly string $result,
        public readonly ?HealthResult $health,
        public readonly array $migrationsRan,
        public readonly bool $migrationsRun,
        public readonly ?float $maintenanceSeconds,
    ) {
    }

    public function healthFailed(): bool
    {
        return $this->health !== null && !$this->health->ok;
    }
}
