<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

final class RotationResult
{
    /**
     * @param int|null    $keyId        the new GitHub key id (null when added by hand)
     * @param string|null $manualDelete what the user must still do about the old key
     */
    public function __construct(
        public readonly ?int $keyId,
        public readonly ?string $manualDelete,
    ) {
    }
}
