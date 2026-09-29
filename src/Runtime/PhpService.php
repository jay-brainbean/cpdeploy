<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Composer\Semver\Semver;
use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Ui\Reporter;

/**
 * Site PHP resolution (PHP-04), the domain's current PHP (PHP-03), per-release
 * PHP (PHP-07), platform-requirement parsing (PHP-05) and the PHP change
 * ordering (GL-03).
 */
final class PhpService
{
    public const CHANGE_NONE = 'none';
    public const CHANGE_UPGRADE = 'upgrade';
    public const CHANGE_DOWNGRADE = 'downgrade';
    public const CHANGE_FAMILY = 'family';

    public function __construct(
        private readonly PhpLocator $locator,
        private readonly MultiPhpService $multiPhp,
        private readonly CloudLinux $cloudLinux,
        private readonly Shell $shell,
    ) {
    }

    /**
     * PHP-04: the binary for a site's php.version / php.family, or E_PHP_MISSING.
     */
    public function resolve(string $majorMinor, string $family = PhpInstall::EA): PhpInstall
    {
        $install = $this->locator->find($majorMinor, $family);
        if ($install === null) {
            throw new CpdeployException(
                ErrorCode::PHP_MISSING,
                sprintf("PHP %s (%s) isn't installed on this server. Installed: %s", $majorMinor, $family, $this->locator->describe()),
                'Choose another version (Manage site → PHP version), or ask your host to install it.',
            );
        }

        return $install;
    }

    /**
     * PHP-03: the version the domain runs now.
     * 1. its own MultiPHP version;
     * 2. inherited + CloudLinux PHP Selector → what /usr/local/bin/php reports in the docroot;
     * 3. inherited otherwise → the system default.
     */
    public function domainPhp(Domain $domain): ?DomainPhp
    {
        $vhost = $this->multiPhp->vhost($domain->name);
        if ($vhost !== null && !$vhost->inherited && ($parsed = PhpInstall::parseTag($vhost->version)) !== null) {
            return new DomainPhp($parsed[0], $parsed[1], DomainPhp::SOURCE_MULTIPHP, false);
        }

        if ($this->cloudLinux->selectorAvailable()) {
            $selector = $this->selectorPhp($vhost->documentRoot ?? $domain->documentRoot);
            if ($selector !== null) {
                return $selector;
            }
        }

        $default = $this->multiPhp->systemDefault();
        $parsed = $default !== null ? PhpInstall::parseTag($default) : null;
        if ($parsed === null && $vhost !== null) {
            $parsed = PhpInstall::parseTag($vhost->version);
        }

        return $parsed === null ? null : new DomainPhp($parsed[0], $parsed[1], DomainPhp::SOURCE_SYSTEM_DEFAULT, true);
    }

    /**
     * Runs /usr/local/bin/php from the docroot: on CloudLinux it reflects the
     * PHP Selector choice. An /opt/alt/phpNN/ binary means family alt.
     */
    private function selectorPhp(string $docroot): ?DomainPhp
    {
        $bin = $this->cloudLinux->selectorPhpBinary();
        if (!is_executable($bin) || !is_dir($docroot)) {
            return null;
        }
        $result = $this->shell->run([$bin, '-r', 'echo PHP_BINARY,"|",PHP_VERSION;'], new RunOptions(cwd: $docroot, timeout: 15, label: 'selector php'));
        if (!$result->successful() || preg_match('/^(\S+)\|(\d+\.\d+)/', trim($result->stdout), $m) !== 1) {
            return null;
        }
        $family = preg_match('#/opt/alt/php\d+/#', $m[1]) === 1 ? PhpInstall::ALT : PhpInstall::EA;

        return new DomainPhp($family, $m[2], DomainPhp::SOURCE_SELECTOR, true);
    }

    /**
     * PHP-07: commands inside an existing release use the PHP recorded in its
     * .release.json while that binary exists, else the site PHP with a warning.
     */
    public function forRelease(?string $recordedBinary, PhpInstall $sitePhp, ?Reporter $reporter = null): string
    {
        if ($recordedBinary !== null && $recordedBinary !== '' && is_executable($recordedBinary)) {
            return $recordedBinary;
        }
        if ($recordedBinary !== null && $recordedBinary !== '') {
            $reporter?->warn(sprintf('%s no longer exists; using the site PHP %s instead', $recordedBinary, $sitePhp->version));
        }

        return $sitePhp->binary;
    }

    /**
     * GL-03 input: how the domain's PHP changes when it moves from $current to $new.
     */
    public static function phpChange(?string $currentTag, string $newTag): string
    {
        $cur = $currentTag !== null ? PhpInstall::parseTag($currentTag) : null;
        $new = PhpInstall::parseTag($newTag);
        if ($cur === null || $new === null || $cur === $new) {
            return self::CHANGE_NONE;
        }
        if ($cur[0] !== $new[0]) {
            return self::CHANGE_FAMILY;
        }

        return version_compare($new[1], $cur[1], '>') ? self::CHANGE_UPGRADE : self::CHANGE_DOWNGRADE;
    }

    /**
     * GL-03: newer PHP usually runs older code, so upgrades (and family changes)
     * switch the domain before the code; downgrades after.
     */
    public static function switchesBeforeCode(string $change): bool
    {
        return $change === self::CHANGE_UPGRADE || $change === self::CHANGE_FAMILY;
    }

    /**
     * PHP-05: problems reported by `composer check-platform-reqs --lock --no-dev
     * --format=json`. Any entry whose status isn't "success" is a problem. When the
     * output isn't JSON, fall back to the text table (lines ending in "missing" or
     * "failed"), and finally to the exit code.
     *
     * @return list<string> e.g. ["ext-intl (missing)", "php 8.1.34 (requires ^8.2)"]
     */
    public static function platformProblems(string $output, int $exitCode): array
    {
        $data = json_decode(trim($output), true);
        if (is_array($data)) {
            $problems = [];
            foreach ($data as $row) {
                if (!is_array($row) || ($row['status'] ?? 'success') === 'success') {
                    continue;
                }
                $name = is_string($row['name'] ?? null) ? $row['name'] : 'requirement';
                $req = is_array($row['failed_requirement'] ?? null) ? $row['failed_requirement'] : [];
                $constraint = is_string($req['constraint'] ?? null) ? $req['constraint'] : null;
                $version = is_string($row['version'] ?? null) ? $row['version'] : null;
                $status = is_string($row['status'] ?? null) ? $row['status'] : 'failed';
                $problems[] = $status === 'missing' || $version === null
                    ? "{$name} (missing)"
                    : sprintf('%s %s (requires %s)', $name, $version, $constraint ?? '?');
            }

            return $problems;
        }

        $problems = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(\S+)\s+.*\s(missing|failed)$/', $line, $m) === 1) {
                $problems[] = "{$m[1]} ({$m[2]})";
            }
        }
        if ($problems === [] && $exitCode !== 0) {
            $problems[] = 'the platform check failed (exit code ' . $exitCode . ')';
        }

        return $problems;
    }

    /**
     * PHP-05 without composer.lock: require.php via composer/semver, ext-* keys
     * against the binary's extensions.
     *
     * @param array<string, string> $require composer.json "require"
     * @return list<string>
     */
    public function problemsWithoutLock(array $require, PhpInstall $php): array
    {
        $problems = [];
        $constraint = $require['php'] ?? null;
        if (is_string($constraint) && $constraint !== '' && !Semver::satisfies($php->version, $constraint)) {
            $problems[] = sprintf('php %s (requires %s)', $php->version, $constraint);
        }
        $loaded = $this->locator->extensions($php->binary);
        foreach (array_keys($require) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $ext = strtolower(substr($package, 4));
                if (!in_array($ext, $loaded, true)) {
                    $problems[] = "{$package} (missing)";
                }
            }
        }

        return $problems;
    }
}
