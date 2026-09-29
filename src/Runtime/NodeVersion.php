<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

/**
 * One installed Node (NODE-03).
 */
final class NodeVersion
{
    public function __construct(
        public readonly string $version,
        public readonly string $binDir,
    ) {
    }

    public function node(): string
    {
        return $this->binDir . '/node';
    }

    public function major(): int
    {
        return (int) explode('.', $this->version)[0];
    }
}
