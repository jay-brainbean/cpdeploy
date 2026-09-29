<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Version;

/**
 * Protect, unprotect and delete a release under the site lock, refusing while
 * an interrupted operation waits for recovery (REC-01). Used by `cpdeploy
 * releases` and Manage site → Releases (ARC-03).
 */
final class ReleaseActions
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly ReleaseManager $releases,
    ) {
    }

    public function protect(string $site, string $id, bool $protected): Release
    {
        return $this->locked($site, fn (): Release => $this->releases->protect($site, $id, $protected));
    }

    public function delete(string $site, string $id): void
    {
        $this->locked($site, function () use ($site, $id): bool {
            $this->releases->delete($site, $id);

            return true;
        });
    }

    /**
     * @template T
     * @param \Closure(): T $action
     * @return T
     */
    private function locked(string $site, \Closure $action): mixed
    {
        $lock = Lock::site($this->paths->siteLock($site), $site, 'releases', $this->system->userName(), Version::get(), $this->clock);
        try {
            $state = new StateFile($this->paths->stateFile($site), $this->fs);
            if ($state->exists()) {
                throw Recovery::interrupted($site, $state->read() ?? []);
            }

            return $action();
        } finally {
            $lock->release();
        }
    }
}
