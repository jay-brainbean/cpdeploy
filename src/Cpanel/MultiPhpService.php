<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * MultiPHP through LangPHP (CP-04, CP-05).
 *
 * Real php_get_vhost_versions entries carry: vhost, version, documentroot,
 * php_fpm (1/0), main_domain, account, homedir, phpversion_source, php_fpm_pool_parms.
 * A vhost with its own version has phpversion_source {"domain": "<vhost>"}.
 */
final class MultiPhpService
{
    public function __construct(private readonly Uapi $uapi)
    {
    }

    /**
     * @return list<string> e.g. ["ea-php81", "ea-php82"]
     */
    public function installedVersions(): array
    {
        $data = $this->uapi->call('LangPHP', 'php_get_installed_versions');
        $versions = is_array($data) && is_array($data['versions'] ?? null) ? $data['versions'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? $v : '',
            $versions,
        ), static fn (string $v): bool => $v !== ''));
    }

    public function systemDefault(): ?string
    {
        $data = $this->uapi->call('LangPHP', 'php_get_system_default_version');

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
    }

    /**
     * @return array<string, VhostPhp> keyed by vhost (lower-case)
     */
    public function vhostVersions(): array
    {
        $data = $this->uapi->call('LangPHP', 'php_get_vhost_versions');
        $out = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (!is_array($row) || !is_string($row['vhost'] ?? null)) {
                continue;
            }
            $source = $row['phpversion_source'] ?? null;
            $out[strtolower($row['vhost'])] = new VhostPhp(
                $row['vhost'],
                is_string($row['version'] ?? null) ? $row['version'] : '',
                is_string($row['documentroot'] ?? null) ? rtrim($row['documentroot'], '/') : '',
                (int) ($row['php_fpm'] ?? 0) === 1,
                self::isInherited($source, $row['vhost']),
                (int) ($row['main_domain'] ?? 0) === 1,
            );
        }

        return $out;
    }

    public function vhost(string $domain): ?VhostPhp
    {
        return $this->vhostVersions()[strtolower($domain)] ?? null;
    }

    /**
     * Sets the domain's PHP version. The caller must first make sure the docroot
     * has an .htaccess (CP-05); cPanel refuses otherwise.
     */
    public function setVhostVersion(string $vhost, string $version, ?int $exitCode = null): void
    {
        $this->uapi->call('LangPHP', 'php_set_vhost_versions', ['vhost' => $vhost, 'version' => $version], $exitCode);
    }

    /**
     * A vhost inherits the system default when its version doesn't come from its own
     * setting. Real data only showed {"domain": "<vhost>"} (own setting); any other
     * source ({"system_default": …}, another domain, or none) counts as inherited.
     */
    public static function isInherited(mixed $source, string $vhost): bool
    {
        if (!is_array($source)) {
            return $source === null || $source === '' || $source === 'system_default';
        }
        if (isset($source['system_default'])) {
            return true;
        }
        $domain = $source['domain'] ?? null;

        return !is_string($domain) || strtolower($domain) !== strtolower($vhost);
    }
}
