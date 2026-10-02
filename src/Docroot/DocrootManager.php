<?php

declare(strict_types=1);

namespace Cpdeploy\Docroot;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * The domain's document root (§7.14): safety checks (DOC-01), the first
 * conversion into a symlink (DOC-02), `.htaccess` for MultiPHP (DOC-03), the PHP
 * handler block (DOC-04) and re-pointing after a web_dir change (DOC-08).
 */
final class DocrootManager
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly SiteRegistry $sites,
    ) {
    }

    /**
     * Absolute path the docroot symlink points at: current/<web_dir>.
     */
    public function linkTarget(SiteConfig $config): string
    {
        $current = $this->paths->current($config->name());

        return $config->webDir() === '' ? $current : $current . '/' . $config->webDir();
    }

    /**
     * Where the docroot symlink points (resolved lexically), or null when it isn't a symlink.
     */
    public function symlinkTarget(string $docroot): ?string
    {
        if (!is_link($docroot)) {
            return null;
        }
        $target = readlink($docroot);
        if ($target === false) {
            return null;
        }

        return str_starts_with($target, '/') ? Fs::normalize($target) : Fs::normalize(dirname($docroot) . '/' . $target);
    }

    /**
     * True once the docroot is this site's symlink (converted at a first go-live).
     */
    public function isConverted(SiteConfig $config): bool
    {
        $target = $this->symlinkTarget($config->docroot());

        return $target !== null && Fs::isInside($target, Fs::normalize($this->paths->siteDir($config->name())));
    }

    /**
     * DOC-08: the docroot is ours but points somewhere other than current/<web_dir>.
     */
    public function needsRepoint(SiteConfig $config): bool
    {
        return $this->isConverted($config) && $this->symlinkTarget($config->docroot()) !== Fs::normalize($this->linkTarget($config));
    }

    /**
     * DOC-01 a–g. Every problem found, as a message; [] = safe.
     *
     * @param list<Domain> $domains from DomainInfo::domains_data
     * @return list<string>
     */
    public function problems(SiteConfig $config, array $domains): array
    {
        $problems = $this->sites->docrootProblems($config); // a, b, c, e
        $docroot = Fs::normalize($config->docroot());

        // (g) the domain still exists and cPanel still serves it from this path.
        $domain = null;
        foreach ($domains as $d) {
            if (strtolower($d->name) === strtolower($config->domain())) {
                $domain = $d;
                break;
            }
        }
        if ($domain === null) {
            $problems[] = sprintf('The domain %s no longer exists in this cPanel account. Add it again in cPanel → Domains, or remove the site.', $config->domain());
        } elseif ($domain->documentRoot !== '' && Fs::normalize($domain->documentRoot) !== $docroot) {
            $problems[] = sprintf(
                'cPanel now serves %s from %s, but cpdeploy manages %s. Run: cpdeploy config %s set domain.docroot %s',
                $config->domain(),
                $domain->documentRoot,
                $config->docroot(),
                $config->name(),
                $domain->documentRoot,
            );
        }

        // (d) no other domain's document root inside this docroot.
        if (!$this->isConverted($config)) {
            $nested = [];
            foreach ($domains as $d) {
                if ($d->type === Domain::PARKED || $d->documentRoot === '' || strtolower($d->name) === strtolower($config->domain())) {
                    continue;
                }
                if (Fs::isInside(Fs::normalize($d->documentRoot), $docroot)) {
                    $nested[] = $d->name;
                }
            }
            if ($nested !== []) {
                $problems[] = sprintf(
                    '%s contains the folders of other domains: %s. Converting it would take those sites offline. '
                    . 'Move those domains\' document roots outside it first (cPanel → Domains), or use a different domain for this site.',
                    basename($docroot),
                    implode(', ', array_slice($nested, 0, 5)) . (count($nested) > 5 ? ', …' : ''),
                );
            }
        }

        // (f) an existing symlink must point inside this site's folder.
        if (is_link($docroot) && !$this->isConverted($config)) {
            $problems[] = sprintf('%s is a symlink that cpdeploy did not create (it points to %s). cpdeploy never rewrites it; point the domain at a real folder first.', $config->docroot(), (string) readlink($docroot));
        } elseif (file_exists($docroot) && !is_dir($docroot)) {
            $problems[] = "{$config->docroot()} is a file, not a folder";
        }

        return $problems;
    }

    /**
     * Throws E_DOCROOT_UNSAFE listing every DOC-01 problem.
     *
     * @param list<Domain> $domains
     */
    public function assertSafe(SiteConfig $config, array $domains, ?int $exitCode = null): void
    {
        $problems = $this->problems($config, $domains);
        if ($problems !== []) {
            throw new CpdeployException(
                ErrorCode::DOCROOT_UNSAFE,
                count($problems) === 1 ? $problems[0] : "The document root isn't safe to manage:\n  - " . implode("\n  - ", $problems),
                'Fix the problem above in cPanel → Domains (or with cpdeploy config), then deploy again.',
                exitCode: $exitCode,
            );
        }
    }

    /**
     * The folder the web server serves now: the live release's web dir once
     * converted, else the docroot folder itself (DOC-03, DOC-04).
     */
    public function servedFolder(SiteConfig $config): string
    {
        if ($this->isConverted($config)) {
            $real = realpath($config->docroot());
            if ($real !== false) {
                return $real;
            }
        }

        return $config->docroot();
    }

    /**
     * DOC-03: MultiPHP refuses a docroot without .htaccess; create an empty one.
     */
    public function ensureHtaccess(string $folder): void
    {
        $file = $folder . '/.htaccess';
        if (is_dir($folder) && !file_exists($file)) {
            $this->fs->writeAtomic($file, '', Paths::MODE_PUBLIC_FILE);
        }
    }

    /**
     * DOC-04 capture: the handler block from <folder>/.htaccess into
     * shared/php-handler.block. Returns whether a block was found.
     */
    public function captureHandler(SiteConfig $config, string $folder): bool
    {
        $content = @file_get_contents($folder . '/.htaccess');
        $block = is_string($content) ? HandlerBlock::capture($content) : null;
        if ($block === null) {
            return false;
        }
        $this->fs->ensureDir($this->paths->sharedDir($config->name()), Paths::MODE_ROOT);
        $this->fs->writeAtomic($this->paths->handlerBlock($config->name()), $block . "\n", Paths::MODE_PUBLIC_FILE);

        return true;
    }

    /**
     * The captured block rewritten for $family/$majorMinor, or null when none was captured.
     */
    public function handlerFor(SiteConfig $config, string $family, string $majorMinor): ?string
    {
        $raw = @file_get_contents($this->paths->handlerBlock($config->name()));
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return HandlerBlock::rewrite(rtrim($raw, "\r\n"), $family, $majorMinor);
    }

    /**
     * DOC-04 inject into <webPath>/.htaccess (created when missing). No block → nothing.
     */
    public function injectHandler(SiteConfig $config, string $webPath, string $family, string $majorMinor): bool
    {
        $block = $this->handlerFor($config, $family, $majorMinor);
        if ($block === null || !is_dir($webPath)) {
            return false;
        }
        $file = $webPath . '/.htaccess';
        if (is_link($file)) {
            return false;
        }
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        $this->fs->writeAtomic($file, HandlerBlock::inject($existing, $block), Paths::MODE_PUBLIC_FILE);

        return true;
    }

    /**
     * A Laravel web dir whose .htaccess has no rules of its own gets Laravel's
     * front-controller rules (see LaravelRewrites). Returns whether they were added.
     */
    public function ensureLaravelRewrites(string $webPath): bool
    {
        $file = $webPath . '/.htaccess';
        if (!is_file($webPath . '/index.php') || is_link($file)) {
            return false;
        }
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if (!LaravelRewrites::needed($existing)) {
            return false;
        }
        $this->fs->writeAtomic($file, LaravelRewrites::add($existing), Paths::MODE_PUBLIC_FILE);

        return true;
    }

    /**
     * Copies docroot extras (shared.docroot_dirs/files) from a real folder into
     * shared/docroot. Existing folders are merged; files are replaced.
     *
     * @return list<string> what was copied
     */
    public function copyExtras(SiteConfig $config, string $from): array
    {
        $shared = $this->paths->sharedDocrootDir($config->name());
        $this->fs->ensureDir($this->paths->sharedDir($config->name()), Paths::MODE_ROOT);
        $this->fs->ensureDir($shared, Paths::MODE_ROOT);
        $copied = [];
        foreach ($config->docrootDirs() as $dir) {
            $src = $from . '/' . $dir;
            if (is_dir($src) && !is_link($src)) {
                $this->fs->ensureDir($shared . '/' . $dir, Paths::MODE_PUBLIC_DIR);
                $this->fs->copyTree($src . '/.', $shared . '/' . $dir);
                $copied[] = $dir;
            }
        }
        foreach ($config->docrootFiles() as $file) {
            $src = $from . '/' . $file;
            if (is_file($src) && !is_link($src)) {
                $this->fs->ensureDir(dirname($shared . '/' . $file), Paths::MODE_PUBLIC_DIR);
                $this->fs->writeAtomic($shared . '/' . $file, (string) file_get_contents($src), Paths::MODE_PUBLIC_FILE);
                $copied[] = $file;
            }
        }

        return $copied;
    }

    /**
     * DOC-02: turns the docroot into a relative symlink to current/<web_dir>.
     * A missing docroot just gets the link. A folder is renamed to
     * backups/docroot-<ts> (same filesystem only) after its handler block and
     * extras are saved. Returns the backup path relative to the site folder, or null.
     *
     * @param list<string> $notices filled with things the user should know
     */
    public function convert(SiteConfig $config, string $stamp, array &$notices): ?string
    {
        $docroot = $config->docroot();
        $target = $this->linkTarget($config);

        if (!file_exists($docroot) && !is_link($docroot)) {
            $this->fs->ensureDir(dirname($docroot), Paths::MODE_ROOT);
            $this->link($docroot, $target);

            return null;
        }
        if (is_link($docroot) || !is_dir($docroot)) {
            throw new CpdeployException(ErrorCode::DOCROOT_UNSAFE, "{$docroot} can't be converted: it isn't a folder cpdeploy may move", 'See: cpdeploy check', exitCode: 6);
        }

        $htaccess = @file_get_contents($docroot . '/.htaccess');
        $this->captureHandler($config, $docroot);
        $this->copyExtras($config, $docroot);
        if (is_string($htaccess) && HandlerBlock::hasOtherRules($htaccess)) {
            $notices[] = 'Your old .htaccess had extra rules (shown in the log). They are not carried over — add them to '
                . ($config->webDir() === '' ? '' : $config->webDir() . '/') . '.htaccess in your repo if you need them.';
        }
        if (is_dir($docroot . '/cgi-bin')) {
            $notices[] = 'The old cgi-bin folder is in the backup. Add cgi-bin to shared.docroot_dirs if you need CGI.';
        }

        $backups = $this->paths->backupsDir($config->name());
        $this->fs->ensureDir($backups, Paths::MODE_PRIVATE_DIR);
        $backup = $this->paths->docrootBackup($config->name(), $stamp);
        for ($i = 2; file_exists($backup); $i++) {
            $backup = $this->paths->docrootBackup($config->name(), $stamp . '-' . $i);
        }
        $from = @stat($docroot);
        $to = @stat($backups);
        // PHP's rename() copies across filesystems; refuse instead (nothing is copied).
        if ($from === false || $to === false || $from['dev'] !== $to['dev'] || !@rename($docroot, $backup)) {
            throw new CpdeployException(
                ErrorCode::DOCROOT_MOVE,
                "Couldn't move {$docroot} to " . $backups . ($from !== false && $to !== false && $from['dev'] !== $to['dev'] ? ' (it is on another filesystem)' : ''),
                'See the log. Nothing was moved.',
            );
        }
        try {
            $this->link($docroot, $target);
        } catch (RuntimeException $e) {
            @rename($backup, $docroot);
            throw new CpdeployException(ErrorCode::GOLIVE, "Couldn't create the docroot symlink: {$e->getMessage()}", 'The original folder was put back.');
        }

        return substr($backup, strlen($this->paths->siteDir($config->name())) + 1);
    }

    /**
     * Undoes convert() after a failed go-live: the symlink goes, the backup comes back.
     */
    public function undoConversion(SiteConfig $config, ?string $backup): void
    {
        $docroot = $config->docroot();
        if (is_link($docroot) && $this->isConverted($config)) {
            @unlink($docroot);
        }
        if ($backup !== null) {
            $path = $this->paths->siteDir($config->name()) . '/' . $backup;
            if (!file_exists($docroot) && is_dir($path)) {
                @rename($path, $docroot);
            }
        }
    }

    /**
     * DOC-08: re-points our docroot symlink at current/<web_dir> (atomic, FS-02).
     */
    public function repoint(SiteConfig $config): void
    {
        $this->link($config->docroot(), $this->linkTarget($config));
    }

    private function link(string $docroot, string $target): void
    {
        $this->fs->linkRelative($docroot, $target);
    }
}
