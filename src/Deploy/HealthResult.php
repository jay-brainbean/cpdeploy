<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * Outcome of the health check (§11.6), recorded in the history notes (HC-04).
 */
final class HealthResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly float $seconds,
        public readonly string $url,
        public readonly int $attempts,
        public readonly array $warnings = [],
        public readonly string $error = '',
    ) {
    }

    /**
     * "health: 200 in 0.4s" (HC-04).
     */
    public function note(): string
    {
        return $this->status > 0
            ? sprintf('health: %d in %.1fs', $this->status, $this->seconds)
            : 'health: no response (' . ($this->error !== '' ? $this->error : 'connection failed') . ')';
    }
}
