<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Throwable;

/**
 * Finds installed PHP binaries (PHP-01) and their extensions (PHP-02).
 *
 * Scans <root>/ea-phpNN/root/usr/bin/php (EasyApache, family "ea") and
 * <root>/phpNN/usr/bin/php (CloudLinux alt-php, family "alt"). Only CLI binaries
 * are kept: on real servers /usr/bin/php can be php-cgi. Results are cached for
 * the run.
 */
final class PhpLocator
{
    public const PROBE = 'echo PHP_VERSION,"|",PHP_SAPI;';

    /** @var list<PhpInstall>|null */
    private ?array $installs = null;

    /** @var array<string, list<string>> */
    private array $extensions = [];

    /**
     * @param list<string> $eaRoots  folders holding ea-phpNN (default /opt/cpanel)
     * @param list<string> $altRoots folders holding phpNN (default /opt/alt)
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly ?MultiPhpService $multiPhp,
        private readonly array $eaRoots = ['/opt/cpanel'],
        private readonly array $altRoots = ['/opt/alt'],
    ) {
    }

    /**
     * Newest first.
     *
     * @return list<PhpInstall>
     */
    public function installs(): array
    {
        if ($this->installs !== null) {
            return $this->installs;
        }

        $multiPhp = null;
        if ($this->multiPhp !== null) {
            try {
                $multiPhp = $this->multiPhp->installedVersions();
            } catch (Throwable) {
                $multiPhp = null;
            }
        }

        $candidates = [];
        foreach ($this->eaRoots as $root) {
            foreach (glob(rtrim($root, '/') . '/ea-php[0-9]*/root/usr/bin/php') ?: [] as $bin) {
                $candidates[$bin] = PhpInstall::EA;
            }
        }
        foreach ($this->altRoots as $root) {
            foreach (glob(rtrim($root, '/') . '/php[0-9]*/usr/bin/php') ?: [] as $bin) {
                $candidates[$bin] = PhpInstall::ALT;
            }
        }

        $installs = [];
        foreach ($candidates as $bin => $family) {
            if (!is_executable($bin)) {
                continue;
            }
            $probe = $this->shell->run([$bin, '-r', self::PROBE], new RunOptions(timeout: 15, label: 'php probe'));
            if (!$probe->successful() || preg_match('/^(\d+\.\d+\.\d+)\S*\|(\S+)$/', trim($probe->stdout), $m) !== 1) {
                continue;
            }
            if ($m[2] !== 'cli') {
                continue;
            }
            $tag = PhpInstall::tagFor($family, PhpInstall::majorMinorOf($m[1]));
            $in = $family === PhpInstall::EA && $multiPhp !== null ? in_array($tag, $multiPhp, true) : null;
            $installs[] = new PhpInstall($family, $m[1], $bin, $in);
        }

        // Newest first; for the same version, ea before alt.
        usort($installs, static fn (PhpInstall $a, PhpInstall $b): int => version_compare($b->version, $a->version) ?: strcmp($b->family, $a->family));

        return $this->installs = $installs;
    }

    public function find(string $majorMinor, string $family = PhpInstall::EA): ?PhpInstall
    {
        foreach ($this->installs() as $install) {
            if ($install->family === $family && $install->majorMinor() === $majorMinor) {
                return $install;
            }
        }

        return null;
    }

    public function findTag(string $tag): ?PhpInstall
    {
        $parsed = PhpInstall::parseTag($tag);

        return $parsed === null ? null : $this->find($parsed[1], $parsed[0]);
    }

    /**
     * Loaded extensions, lower-cased; "zend opcache" is reported as "opcache" (PHP-02).
     *
     * @return list<string>
     */
    public function extensions(string $binary): array
    {
        if (isset($this->extensions[$binary])) {
            return $this->extensions[$binary];
        }
        $result = $this->shell->run([$binary, '-m'], new RunOptions(timeout: 15, label: 'php -m'));

        return $this->extensions[$binary] = self::parseModules($result->stdout);
    }

    /**
     * @return list<string>
     */
    public static function parseModules(string $output): array
    {
        $modules = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '[')) {
                continue;
            }
            $modules[$line === 'zend opcache' ? 'opcache' : $line] = true;
        }

        return array_keys($modules);
    }

    /**
     * "8.3 (ea), 8.2 (ea), 8.2 (alt)" for messages.
     */
    public function describe(): string
    {
        $list = array_map(
            static fn (PhpInstall $i): string => $i->majorMinor() . ' (' . $i->family . ')',
            $this->installs(),
        );

        return $list === [] ? 'none' : implode(', ', $list);
    }
}
