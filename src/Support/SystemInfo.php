<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Facts about the running PHP and user. Wrapped so checks can be tested.
 */
class SystemInfo
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function phpBinary(): string
    {
        return PHP_BINARY;
    }

    public function hasExtension(string $name): bool
    {
        return extension_loaded($name);
    }

    /**
     * @param list<string> $functions
     * @return list<string> the ones listed in disable_functions
     */
    public function disabledFunctions(array $functions): array
    {
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return array_values(array_filter($functions, static fn (string $f): bool => in_array($f, $disabled, true)));
    }

    public function isRoot(): bool
    {
        return function_exists('posix_geteuid') ? posix_geteuid() === 0 : $this->userName() === 'root';
    }

    public function userName(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return $info['name'];
            }
        }

        return $this->environment->get('USER') ?? 'unknown';
    }

    public function hostName(): string
    {
        return gethostname() ?: 'localhost';
    }

    /**
     * CP-03: CPDEPLOY_UAPI_BIN overrides the binary in test mode.
     */
    public function uapiBinary(): string
    {
        return $this->environment->testing('CPDEPLOY_UAPI_BIN') ?? 'uapi';
    }
}
