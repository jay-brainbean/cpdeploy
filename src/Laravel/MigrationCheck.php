<?php

declare(strict_types=1);

namespace Cpdeploy\Laravel;

/**
 * LAR-03 result: whether the pending list is known, and the pending migrations.
 */
final class MigrationCheck
{
    /**
     * @param list<string> $pending
     */
    public function __construct(
        public readonly bool $known,
        public readonly array $pending,
        public readonly string $raw = '',
    ) {
    }

    public static function unknown(string $raw): self
    {
        return new self(false, [], $raw);
    }
}
