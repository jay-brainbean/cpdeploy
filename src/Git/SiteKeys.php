<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;

/**
 * Manage site → Deploy key (§9.5.10) and `cpdeploy key <site> show|test|rotate`:
 * a site's deploy key through DeployKeyService, with the site's settings.
 */
final class SiteKeys
{
    /**
     * @param Closure(): GitHubApi $api the API with the stored token (if any)
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
        private readonly DeployKeyService $keys,
        private readonly Closure $api,
        private readonly Finisher $finisher,
        private readonly ReleaseManager $releases,
    ) {
    }

    /**
     * @return array{path: string, exists: bool, fingerprint: ?string, public: ?string, id: ?int, keysUrl: string}
     */
    public function info(string $site): array
    {
        $config = $this->sites->load($site);
        $exists = $this->keys->exists($site);

        return [
            'path' => $this->keys->keyPath($site),
            'exists' => $exists,
            'fingerprint' => $exists ? $this->keys->fingerprint($site) : null,
            'public' => $exists ? trim($this->keys->publicKey($site)) : null,
            'id' => $config->deployKeyId(),
            'keysUrl' => $config->repo()->keysUrl(),
        ];
    }

    /**
     * GIT-06. Returns how many branches the key can see.
     */
    public function test(string $site): int
    {
        $config = $this->sites->load($site);

        return count($this->keys->test($config->repo(), $site, $config->transport()));
    }

    /**
     * GIT-18 under the site lock; the new key id is saved and a `key-rotate`
     * history entry written. Any failure leaves the old key in place.
     *
     * @param Closure(ManualKeyInstructions): bool $addManually
     */
    public function rotate(string $site, Closure $addManually, ?Reporter $reporter = null): RotationResult
    {
        $config = $this->sites->load($site);
        $lock = Lock::site($this->paths->siteLock($site), $site, 'key rotate', $this->system->userName(), Version::get(), $this->clock);
        try {
            $api = ($this->api)();
            $result = $this->keys->rotate(
                $config->repo(),
                $site,
                $config->transport(),
                $this->system->userName(),
                $this->system->hostName(),
                $api->hasToken() ? $api : null,
                $config->deployKeyId(),
                $addManually,
                $this->clock->now()->format('Y-m-d'),
                $reporter,
            );
            $this->sites->save($this->sites->load($site)->with('repo.deploy_key_id', $result->keyId));
            $notes = [$result->keyId !== null ? "new key id {$result->keyId}" : 'new key added by hand'];
            if ($result->manualDelete !== null) {
                $notes[] = $result->manualDelete;
            }
            $this->finisher->history($site, 'key-rotate', $result->manualDelete === null ? 'success' : 'warning', 0, $this->releases->liveId($site), null, null, null, 0.0, null, $this->system->userName(), $notes);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
