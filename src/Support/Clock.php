<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Injectable time source. CPDEPLOY_NOW (test mode only) fixes the time so
 * release ids are deterministic (§16.2).
 */
final class Clock
{
    public function __construct(private readonly ?DateTimeImmutable $fixed = null)
    {
    }

    public static function fromEnvironment(Environment $env): self
    {
        $now = $env->testing('CPDEPLOY_NOW');
        if ($now === null) {
            return new self();
        }

        return new self(new DateTimeImmutable($now, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return ($this->fixed ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * UTC timestamp used for release ids, log names and backups: YYYYMMDD-HHMMSS.
     */
    public function stamp(): string
    {
        return $this->now()->format('Ymd-His');
    }

    /**
     * ISO 8601 UTC, e.g. 2026-09-29T03:06:55Z.
     */
    public function iso(): string
    {
        return $this->now()->format('Y-m-d\TH:i:s\Z');
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }
}
