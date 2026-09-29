<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * One domain of the account, from DomainInfo::domains_data.
 */
final class Domain
{
    public const MAIN = 'main';
    public const ADDON = 'addon';
    public const SUB = 'sub';
    public const PARKED = 'parked';

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $documentRoot,
        public readonly string $ip,
        public readonly ?string $phpVersion = null,
        public readonly ?string $serverName = null,
    ) {
    }

    /**
     * Parked (alias) domains share another domain's document root and can't be a site.
     */
    public function selectable(): bool
    {
        return $this->type !== self::PARKED;
    }
}
