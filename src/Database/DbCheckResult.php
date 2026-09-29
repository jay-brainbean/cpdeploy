<?php

declare(strict_types=1);

namespace Cpdeploy\Database;

/**
 * Outcome of DB-04. $detail is the PDO message, or the missing PDO extension.
 */
final class DbCheckResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $driver,
        public readonly string $detail,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === DbCheck::OK;
    }
}
