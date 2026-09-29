<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * One entry of LangPHP::php_get_vhost_versions.
 */
final class VhostPhp
{
    public function __construct(
        public readonly string $vhost,
        public readonly string $version,
        public readonly string $documentRoot,
        public readonly bool $phpFpm,
        public readonly bool $inherited,
        public readonly bool $mainDomain,
    ) {
    }
}
