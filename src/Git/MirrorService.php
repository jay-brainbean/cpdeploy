<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * A site's bare mirror outside a deploy: bring it up to date (GIT-07/08, one
 * transport re-detect as in GIT-04), then list branches, tags and commits for
 * Manage site → Branch and *Deploy with changes*.
 */
final class MirrorService
{
    public function __construct(
        private readonly Paths $paths,
        private readonly SiteRegistry $sites,
        private readonly GitRepository $git,
        private readonly Transport $transport,
    ) {
    }

    public function update(string $site): void
    {
        $config = $this->sites->load($site);
        try {
            $this->attempt($config, $config->transport());
        } catch (CpdeployException $e) {
            if (!Transport::worthRetrying($e)) {
                throw $e;
            }
            $transport = $this->transport->detect();
            $this->attempt($config, $transport);
            if ($transport !== $config->transport()) {
                $this->sites->save($config->with('repo.transport', $transport));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function branches(string $site): array
    {
        return $this->git->branches($this->mirror($site));
    }

    /**
     * @return list<string>
     */
    public function tags(string $site): array
    {
        return $this->git->tags($this->mirror($site));
    }

    /**
     * The newest commits of $branch.
     *
     * @return list<Commit>
     */
    public function commits(string $site, string $branch, int $limit = 100): array
    {
        return $this->git->log($this->mirror($site), null, 'refs/heads/' . $branch, $limit);
    }

    private function mirror(string $site): string
    {
        $mirror = $this->paths->mirror($site);
        if (!is_dir($mirror)) {
            throw new CpdeployException(ErrorCode::GIT, "{$site} has no copy of its repository yet", "Deploy it once, or run: cpdeploy key {$site} test");
        }

        return $mirror;
    }

    private function attempt(SiteConfig $config, string $transport): void
    {
        $url = $this->transport->url($config->repo(), $transport);
        $key = $this->paths->deployKey($config->name());
        if (!str_starts_with($url, 'file://') && !is_file($key)) {
            throw new CpdeployException(ErrorCode::GIT_AUTH, "The deploy key {$key} is missing", "Rotate the key: cpdeploy key {$config->name()} rotate");
        }
        $ssh = SshCommand::build($key, $this->paths->knownHosts());
        $mirror = $this->paths->mirror($config->name());
        if (!is_dir($mirror)) {
            $this->git->cloneMirror($url, $mirror, $ssh, $config->repo()->fullName());
        } else {
            $this->git->fetch($mirror, $ssh, $config->repo()->fullName());
        }
    }
}
