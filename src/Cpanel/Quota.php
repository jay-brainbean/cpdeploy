<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * Disk and inode quota. A null limit means unlimited.
 */
final class Quota
{
    public function __construct(
        public readonly float $megabytesUsed,
        public readonly ?float $megabyteLimit,
        public readonly int $inodesUsed,
        public readonly ?int $inodeLimit,
    ) {
    }

    public function megabytesFree(): ?float
    {
        return $this->megabyteLimit === null ? null : max(0.0, $this->megabyteLimit - $this->megabytesUsed);
    }

    public function inodesFree(): ?int
    {
        return $this->inodeLimit === null ? null : max(0, $this->inodeLimit - $this->inodesUsed);
    }

    /**
     * Percentage used (0–100+), or null when unlimited.
     */
    public function diskPercent(): ?float
    {
        return $this->megabyteLimit === null ? null : 100.0 * $this->megabytesUsed / $this->megabyteLimit;
    }

    public function inodePercent(): ?float
    {
        return $this->inodeLimit === null ? null : 100.0 * $this->inodesUsed / $this->inodeLimit;
    }
}
