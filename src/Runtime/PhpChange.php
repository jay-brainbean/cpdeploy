<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Deploy\HealthChecker;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\ProjectDetector;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;
use Throwable;

/**
 * Manage site → PHP version (§9.5.4) and `cpdeploy php`: what the site, the
 * domain and the live release use; which installed versions the live commit
 * works with (PHP-05); saving a new version; switching the domain now; the
 * served-PHP probe (HTTP-04). Every change writes a `php-change` history entry.
 */
final class PhpChange
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly DomainService $domains,
        private readonly PhpService $php,
        private readonly PhpLocator $locator,
        private readonly MultiPhpService $multiPhp,
        private readonly DocrootManager $docroots,
        private readonly ComposerInstaller $composer,
        private readonly ComposerInspector $inspector,
        private readonly ProjectDetector $detector,
        private readonly GitRepository $git,
        private readonly HealthChecker $health,
        private readonly Finisher $finisher,
    ) {
    }

    /**
     * The three versions of §9.5.4 step 1, as label => value lines.
     *
     * @return array<string, string>
     */
    public function overview(string $site): array
    {
        $config = $this->sites->load($site);
        $out = [
            'Site setting' => sprintf('PHP %s (%s)%s', $config->phpVersion(), $config->phpFamily(), $config->syncMultiPhp() ? ', sets the domain\'s MultiPHP version at go-live' : ', domain PHP not managed'),
            'Domain now' => $this->describeDomain($config),
        ];
        $live = $this->releases->live($site);
        $out['Live release'] = $live === null ? 'not deployed yet' : sprintf('built with PHP %s (%s)', (string) $live->get('php.version'), $live->id);

        return $out;
    }

    /**
     * @return list<PhpInstall> installed versions, newest first
     */
    public function installs(): array
    {
        return $this->locator->installs();
    }

    /**
     * PHP-05 for $php against the live commit. [] = compatible; null = unknown
     * (no live release, or Composer or the repository copy unavailable).
     *
     * @return list<string>|null
     */
    public function compatibility(string $site, PhpInstall $php, ?Reporter $reporter = null): ?array
    {
        $config = $this->sites->load($site);
        $live = $this->releases->live($site);
        $commit = $live?->commit();
        if ($commit === null || !is_dir($this->paths->mirror($site))) {
            return null;
        }
        try {
            $files = new CommitFiles($this->git, $this->paths->mirror($site), $commit);
            $info = $this->detector->detect($files);
            $phar = $info->hasComposer() && $info->hasLock() ? $this->composer->ensure($config->composerVersion(), $reporter) : '';

            return $this->inspector->platformProblems($info, $files, $php, $phar);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Saves php.version (and family / sync) after checking it is installed.
     */
    public function set(string $site, string $majorMinor, ?string $family = null, ?bool $sync = null): SiteConfig
    {
        $config = $this->sites->load($site);
        $family ??= $config->phpFamily();
        $install = $this->php->resolve($majorMinor, $family); // E_PHP_MISSING
        $lock = $this->lock($site);
        try {
            $before = sprintf('%s (%s)', $config->phpVersion(), $config->phpFamily());
            $updated = $config->with('php.version', $install->majorMinor())->with('php.family', $family);
            if ($sync !== null) {
                $updated = $updated->with('php.sync_multiphp', $sync);
            }
            $this->sites->save($updated);
            $this->history($site, sprintf('site PHP %s → %s (%s)', $before, $install->majorMinor(), $family));

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /**
     * §9.5.4 "switch the domain's PHP right now": DOC-03, php_set_vhost_versions,
     * capture the handler block, then the probe. Returns the probe's answer.
     */
    public function switchNow(string $site, Reporter $reporter): ?string
    {
        $config = $this->sites->load($site);
        $install = $this->php->resolve($config->phpVersion(), $config->phpFamily());
        $lock = $this->lock($site);
        try {
            $served = $this->docroots->servedFolder($config);
            $reporter->start('Domain PHP');
            try {
                $this->docroots->ensureHtaccess($served);
                $this->multiPhp->setVhostVersion($config->domain(), $install->tag(), 6);
            } catch (Throwable $e) {
                $reporter->fail($e->getMessage());
                throw $e instanceof CpdeployException ? $e : new CpdeployException(ErrorCode::MULTIPHP, "Couldn't set PHP {$install->tag()} for {$config->domain()}: " . $e->getMessage(), 'cPanel → MultiPHP Manager.');
            }
            $reporter->succeed($install->tag());
            try {
                $this->docroots->captureHandler($config, $served);
            } catch (Throwable $e) {
                $reporter->warn("Couldn't capture the PHP handler block: " . $e->getMessage());
            }
            $this->history($site, "domain PHP set to {$install->tag()} (without a redeploy)");
        } finally {
            $lock->release();
        }

        return $this->probe($site);
    }

    /**
     * HTTP-04 on the live release (null: no live release, or no version came back).
     */
    public function probe(string $site): ?string
    {
        $config = $this->sites->load($site);
        $live = $this->releases->live($site);
        if ($live === null) {
            return null;
        }
        $web = $live->webPath($config->webDir());
        if (!is_dir($web)) {
            return null;
        }

        return $this->health->servedPhp($config, $config->ip(), $web, $this->fs);
    }

    private function describeDomain(SiteConfig $config): string
    {
        try {
            $domain = $this->domains->find($config->domain());
            $current = $domain !== null ? $this->php->domainPhp($domain) : null;
        } catch (Throwable $e) {
            return 'unknown (' . $e->getMessage() . ')';
        }
        if ($current === null) {
            return 'unknown';
        }

        return match ($current->source) {
            DomainPhp::SOURCE_SELECTOR => "CloudLinux PHP Selector: {$current->family}-php {$current->majorMinor}",
            DomainPhp::SOURCE_SYSTEM_DEFAULT => "{$current->tag()} (the server default)",
            default => "{$current->tag()} (MultiPHP)",
        };
    }

    private function history(string $site, string $note): void
    {
        $this->finisher->history($site, 'php-change', 'success', 0, $this->releases->liveId($site), null, null, null, 0.0, null, $this->system->userName(), [$note]);
    }

    private function lock(string $site): Lock
    {
        return Lock::site($this->paths->siteLock($site), $site, 'php', $this->system->userName(), Version::get(), $this->clock);
    }
}
