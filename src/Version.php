<?php

declare(strict_types=1);

namespace Cpdeploy;

/**
 * The tool's version. Box replaces the placeholder with the git tag at build time (§15.1).
 */
final class Version
{
    public const VERSION = '@package_version@';

    public static function get(): string
    {
        // Built from source: the placeholder was not replaced.
        return str_starts_with(self::VERSION, '@') ? 'dev' : ltrim(self::VERSION, 'v');
    }
}
