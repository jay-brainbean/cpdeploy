<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

/**
 * The PHP a domain uses right now (PHP-03).
 */
final class DomainPhp
{
    public const SOURCE_MULTIPHP = 'multiphp';
    public const SOURCE_SELECTOR = 'selector';
    public const SOURCE_SYSTEM_DEFAULT = 'system_default';

    public function __construct(
        public readonly string $family,
        public readonly string $majorMinor,
        public readonly string $source,
        public readonly bool $inherited,
    ) {
    }

    public function tag(): string
    {
        return PhpInstall::tagFor($this->family, $this->majorMinor);
    }
}
