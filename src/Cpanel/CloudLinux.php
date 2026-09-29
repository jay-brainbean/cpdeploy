<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * CloudLinux and PHP Selector detection. alt-php folders alone don't mean
 * CloudLinux: Imunify360 installs /opt/alt/php* on plain AlmaLinux too.
 */
final class CloudLinux
{
    /**
     * @param string $root filesystem root, "" normally; tests point it at a fake tree
     */
    public function __construct(private readonly string $root = '')
    {
    }

    public function isCloudLinux(): bool
    {
        return is_file($this->root . '/etc/cloudlinux-release');
    }

    public function release(): ?string
    {
        if (!$this->isCloudLinux()) {
            return null;
        }
        $text = trim((string) @file_get_contents($this->root . '/etc/cloudlinux-release'));

        return $text !== '' ? $text : 'CloudLinux';
    }

    /**
     * PHP Selector is usable when this is CloudLinux and alt-php versions exist.
     */
    public function selectorAvailable(): bool
    {
        return $this->isCloudLinux() && (glob($this->root . '/opt/alt/php[0-9]*/usr/bin/php') ?: []) !== [];
    }

    public function cageFs(): bool
    {
        return is_dir($this->root . '/var/cagefs') || is_file($this->root . '/bin/cagefs_enter');
    }

    /**
     * /usr/local/bin/php, which on CloudLinux reflects the Selector choice (PHP-03).
     */
    public function selectorPhpBinary(): string
    {
        return $this->root . '/usr/local/bin/php';
    }
}
