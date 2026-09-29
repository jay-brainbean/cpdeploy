<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

/**
 * One installed PHP binary (PHP-01).
 */
final class PhpInstall
{
    public const EA = 'ea';
    public const ALT = 'alt';

    public function __construct(
        public readonly string $family,
        public readonly string $version,
        public readonly string $binary,
        public readonly ?bool $inMultiPhp = null,
    ) {
    }

    /**
     * "8.2" from "8.2.31".
     */
    public function majorMinor(): string
    {
        return self::majorMinorOf($this->version);
    }

    /**
     * cPanel's name: ea-php82 / alt-php82.
     */
    public function tag(): string
    {
        return self::tagFor($this->family, $this->majorMinor());
    }

    public static function majorMinorOf(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    public static function tagFor(string $family, string $majorMinor): string
    {
        return $family . '-php' . str_replace('.', '', $majorMinor);
    }

    /**
     * Parses ea-php82 / alt-php82 / ea-php74 into [family, "8.2"].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseTag(string $tag): ?array
    {
        if (preg_match('/^(ea|alt)-php(\d)(\d+)$/', $tag, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2] . '.' . $m[3]];
    }
}
