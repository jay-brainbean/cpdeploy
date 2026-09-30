<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * What *Remove site* does (§9.5.14, `remove` flags).
 */
final class RemoveOptions
{
    public function __construct(
        /** DocrootDetach::DETACH | RESTORE | EMPTY; null when the site never went live (RM-03). */
        public readonly ?string $docroot,
        public readonly bool $removeKey = true,
        public readonly bool $deleteShared = false,
        public readonly bool $dropDatabase = false,
    ) {
    }
}
