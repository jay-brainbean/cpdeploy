<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * What a rollback did (§11.8), for the command, the deploy's HC-03 path and history.
 */
final class RollbackResult
{
    /**
     * @param list<string> $notes
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $result,
        public readonly int $exitCode,
        public readonly string $target,
        public readonly ?string $from,
        public readonly ?string $targetCommit,
        public readonly ?string $fromCommit,
        public readonly array $notes = [],
        public readonly array $warnings = [],
        public readonly string $message = '',
        public readonly ?HealthResult $health = null,
    ) {
    }
}
