<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

final class MysqlRestrictions
{
    public function __construct(
        public readonly ?string $prefix,
        public readonly int $maxDatabaseNameLength,
        public readonly int $maxUserNameLength,
    ) {
    }
}
