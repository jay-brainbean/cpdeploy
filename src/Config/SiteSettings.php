<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Version;

/**
 * Changes to site.yml made from the menus and the `node` command: validated
 * (§8.3) and saved under the site lock. Node changes write a `node-change`
 * history entry (§8.6).
 */
final class SiteSettings
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly Finisher $finisher,
    ) {
    }

    /**
     * Sets several settings at once (path => value); returns the saved config.
     *
     * @param array<string, mixed> $changes
     */
    public function change(string $site, array $changes): SiteConfig
    {
        $lock = Lock::site($this->paths->siteLock($site), $site, 'config', $this->system->userName(), Version::get(), $this->clock);
        try {
            $config = $this->sites->load($site);
            foreach ($changes as $path => $value) {
                $config = $config->with($path, $value);
            }
            // Validates (§8.3): throws E_CONFIG_INVALID and saves nothing on a problem.
            $this->sites->save($config);

            return $config;
        } finally {
            $lock->release();
        }
    }

    /**
     * §9.5.5: auto | none | a version or range.
     */
    public function setNode(string $site, string $version): SiteConfig
    {
        $version = trim($version);
        if ($version === '') {
            throw new CpdeployException(ErrorCode::USAGE, 'Which Node version? auto, none, or a version such as 20', "Example: cpdeploy node {$site} 20");
        }
        $before = $this->sites->load($site)->nodeVersion();
        $config = $this->change($site, ['node.version' => $version]);
        $this->finisher->history($site, 'node-change', 'success', 0, $this->releases->liveId($site), null, null, null, 0.0, null, $this->system->userName(), ["node {$before} → {$version}"]);

        return $config;
    }

    /**
     * §9.5.9: the branch a normal deploy uses.
     *
     * @param list<string> $branches the mirror's branches
     */
    public function setBranch(string $site, string $branch, array $branches): SiteConfig
    {
        if (!in_array($branch, $branches, true)) {
            throw new CpdeployException(ErrorCode::REF_NOT_FOUND, "{$branch} isn't a branch of the repository", 'Branches: ' . implode(', ', array_slice($branches, 0, 20)));
        }

        return $this->change($site, ['repo.branch' => $branch]);
    }
}
