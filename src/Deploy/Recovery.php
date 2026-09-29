<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;
use Throwable;

/**
 * Recovery of interrupted operations (§11.9). A state file that exists while the
 * site lock is free means a deploy or rollback died part-way (REC-01). Each
 * action below is idempotent, so recovery can run twice (REC-02).
 */
final class Recovery
{
    /** Phases in which the live site may be affected (for E_INTERRUPTED). */
    private const LIVE_PHASES = [StateFile::MAINTENANCE, StateFile::MIGRATING, StateFile::MULTIPHP, StateFile::SWITCHING, StateFile::CONVERTING_DOCROOT];

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly PhpService $php,
        private readonly DomainService $domains,
        private readonly MultiPhpService $multiPhp,
        private readonly DocrootManager $docroots,
        private readonly Maintenance $maintenance,
        private readonly Finisher $finisher,
        private readonly Masker $masker,
        private readonly Shell $shell,
    ) {
    }

    /**
     * `cpdeploy recover <site>` (§10.2): takes the lock, confirms, recovers.
     */
    public function recover(string $name, bool $yes, Asker $asker, Reporter $reporter): RecoveryResult
    {
        $this->sites->load($name);
        $lock = Lock::site($this->paths->siteLock($name), $name, 'recover', $this->system->userName(), Version::get(), $this->clock);
        try {
            $state = new StateFile($this->paths->stateFile($name), $this->fs);
            if (!$state->exists()) {
                $reporter->info("Nothing to recover: no interrupted operation for {$name}.");

                return new RecoveryResult(RecoveryResult::NOTHING, '', '', $this->releases->liveId($name));
            }
            $data = $state->read() ?? [];
            $reporter->info(self::describe($name, $data));
            if (!$yes) {
                if (!$asker->interactive()) {
                    throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'This needs answers: --yes (to confirm the recovery)', 'Run again with --yes.');
                }
                if (!$asker->confirm('Recover now?', true)) {
                    throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — nothing was changed', "Recover later with: cpdeploy recover {$name}");
                }
            }

            return $this->recoverLocked($name, $reporter);
        } finally {
            $lock->release();
        }
    }

    /**
     * REC-01 at the start of an operation that holds the site lock: nothing to do
     * without a state file; otherwise recover when $recover (or the user agrees on a
     * TTY), else stop with E_INTERRUPTED (exit 11).
     */
    public function beforeOperation(string $name, bool $recover, Asker $asker, Reporter $reporter): void
    {
        $state = new StateFile($this->paths->stateFile($name), $this->fs);
        if (!$state->exists()) {
            return;
        }
        $data = $state->read() ?? [];
        if (!$recover) {
            if ($asker->interactive()) {
                $reporter->warn(self::describe($name, $data));
                $recover = $asker->confirm('Recover it first?', true);
            }
            if (!$recover) {
                throw self::interrupted($name, $data);
            }
        } else {
            $reporter->info(self::describe($name, $data));
        }
        $result = $this->recoverLocked($name, $reporter);
        foreach ($result->checks as $check) {
            $reporter->warn($check);
        }
    }

    /**
     * E_INTERRUPTED for $name's state file.
     *
     * @param array<string, mixed> $data
     */
    public static function interrupted(string $name, array $data): CpdeployException
    {
        return new CpdeployException(
            ErrorCode::INTERRUPTED,
            self::describe($name, $data),
            "Run: cpdeploy recover {$name}" . (($data['operation'] ?? '') === StateFile::DEPLOY ? ', or deploy with --recover' : ''),
            liveAffected: in_array($data['phase'] ?? '', self::LIVE_PHASES, true),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function describe(string $name, array $data): string
    {
        $pid = $data['pid'] ?? null;

        return sprintf(
            'An earlier %s of %s was interrupted (phase: %s%s)',
            is_string($data['operation'] ?? null) ? $data['operation'] : 'operation',
            $name,
            is_string($data['phase'] ?? null) ? $data['phase'] : 'unknown',
            is_int($pid) ? ", PID {$pid}" : '',
        );
    }

    /**
     * REC-02 for the state file of $name; the caller holds the lock. Writes its
     * own log and a `recover` history entry (REC-03).
     */
    public function recoverLocked(string $name, Reporter $reporter): RecoveryResult
    {
        $stateFile = new StateFile($this->paths->stateFile($name), $this->fs);
        $data = $stateFile->read();
        if ($data === null) {
            return new RecoveryResult(RecoveryResult::NOTHING, '', '', $this->releases->liveId($name));
        }
        $site = $this->sites->load($name);
        $started = microtime(true);
        $log = Log::open($this->paths->logsDir($name), 'recover', $this->masker, $this->clock, [
            'tool' => 'cpdeploy ' . Version::get(),
            'site' => $name,
            'user' => $this->system->userName(),
            'host' => $this->system->hostName(),
            'state' => (string) json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);
        $this->shell->attachLog($log);
        $run = new RecoveryRun($site, $data, $reporter, $log);
        try {
            if ($run->operation === StateFile::ROLLBACK) {
                $this->rollbackPhase($run, $run->phase);
            } else {
                $this->deployPhase($run, $run->phase);
            }
            $stateFile->delete();
            $liveId = $this->releases->liveId($name);
            $result = $run->warnings === [] ? DeployResult::SUCCESS : DeployResult::WARNING;
            $this->finisher->history(
                $name,
                'recover',
                $result,
                0,
                $liveId,
                is_string($data['live_before'] ?? null) ? $data['live_before'] : null,
                $liveId !== null ? $this->releases->find($name, $liveId)?->commit() : null,
                null,
                microtime(true) - $started,
                $log->path,
                $this->system->userName(),
                [sprintf('%s interrupted in phase %s', $run->operation, $run->phase), ...$run->actions, ...$run->warnings],
            );
            $reporter->info(sprintf('Recovered %s: the site is on %s.', $name, $liveId ?? 'no release (first deploy)'));
            $log->close($result, 0);

            return new RecoveryResult($result, $run->operation, $run->phase, $liveId, $run->actions, [...$run->warnings, ...$run->checks], $log->path);
        } catch (Throwable $e) {
            $log->write('ERROR recovery failed: ' . $e->getMessage());
            $log->close('failed', 11);
            throw new CpdeployException(
                ErrorCode::INTERRUPTED,
                "Recovery of {$name} failed: " . $e->getMessage(),
                'See the log, fix the problem, then run: cpdeploy recover ' . $name,
                liveAffected: true,
                logPath: $log->path,
                previous: $e,
            );
        } finally {
            $this->shell->attachLog(null);
        }
    }

    /**
     * REC-02, deploy phases (§8.7).
     */
    private function deployPhase(RecoveryRun $run, string $phase): void
    {
        // phpAfter (G7) records `multiphp` after the switch: that is a switched deploy.
        if ($phase === StateFile::MULTIPHP && $run->flag('switched')) {
            $phase = StateFile::SWITCHED;
        }
        switch ($phase) {
            case StateFile::PLANNING:
                $run->act('Nothing had changed yet');

                return;
            case StateFile::BUILDING:
                $this->markFailed($run);

                return;
            case StateFile::MAINTENANCE:
            case StateFile::MIGRATING:
                $this->upBefore($run);
                $this->markFailed($run);
                if ($run->flag('migrations_started')) {
                    $run->check('A deploy was interrupted during migrations. Check `php artisan migrate:status` — some migrations may have run.');
                }
                $this->revertPhp($run);

                return;
            case StateFile::MULTIPHP:
                $this->revertPhp($run);
                $this->upBefore($run);
                $this->markFailed($run);

                return;
            case StateFile::SWITCHING:
                $this->deployPhase($run, $this->pointsAtRelease($run) ? StateFile::CONVERTING_DOCROOT : StateFile::MULTIPHP);

                return;
            case StateFile::CONVERTING_DOCROOT:
                if ($this->docroots->isConverted($run->site) && !$this->docroots->needsRepoint($run->site)) {
                    $this->deployPhase($run, StateFile::SWITCHED);

                    return;
                }
                $this->undoGoLive($run);

                return;
            case StateFile::SWITCHED:
            case StateFile::FINISHING:
                $this->finishSwitched($run);

                return;
            default:
                throw new \RuntimeException("Unknown phase '{$phase}' in the state file");
        }
    }

    /**
     * REC-02, rollback phases (§8.7): preparing, multiphp, switching, switched, finishing.
     */
    private function rollbackPhase(RecoveryRun $run, string $phase): void
    {
        if ($phase === StateFile::MULTIPHP && $run->flag('switched')) {
            $phase = StateFile::SWITCHED;
        }
        switch ($phase) {
            case StateFile::PREPARING:
                $run->act('Nothing had been switched yet');

                return;
            case StateFile::MULTIPHP:
                $this->revertPhp($run);

                return;
            case StateFile::SWITCHING:
                if ($this->pointsAtRelease($run)) {
                    $this->finishSwitched($run);
                } else {
                    $this->revertPhp($run);
                }

                return;
            case StateFile::SWITCHED:
            case StateFile::FINISHING:
                $this->finishSwitched($run);

                return;
            default:
                throw new \RuntimeException("Unknown phase '{$phase}' in the state file");
        }
    }

    /**
     * `converting_docroot` that didn't finish: put the docroot and `current` back
     * as they were, revert MultiPHP, bring L up, mark the release failed.
     */
    private function undoGoLive(RecoveryRun $run): void
    {
        $site = $run->site;
        $docroot = $site->docroot();
        if (is_link($docroot) && $this->docroots->isConverted($site) && $run->liveBefore() === null) {
            @unlink($docroot);
        }
        if (!file_exists($docroot) && !is_link($docroot)) {
            $backup = $this->docrootBackup($run);
            if ($backup !== null && @rename($backup, $docroot)) {
                $run->act('Put the original document root back from ' . basename($backup));
            }
        }
        if (!$this->docroots->isConverted($site) && $site->convertedAt() !== null) {
            // Converted again at the next go-live (DOC-02).
            $site = $site->with('domain.converted_at', null)->with('domain.backup', null);
            $this->sites->save($site);
            $run->site = $site;
        }

        $current = $this->paths->current($site->name());
        $liveBefore = $run->liveBefore();
        if ($liveBefore === null) {
            if (is_link($current)) {
                @unlink($current);
                $run->act('Removed `current` (it was the first deploy)');
            }
        } else {
            $this->fs->swapSymlink($current, 'releases/' . $liveBefore);
            $run->act("Pointed `current` back to {$liveBefore}");
        }
        $this->syncStatuses($run);
        $this->revertPhp($run);
        $this->upBefore($run);
        $this->markFailed($run);
    }

    /**
     * `switched` / `finishing`: the switch happened, only the bookkeeping is left.
     */
    private function finishSwitched(RecoveryRun $run): void
    {
        $name = $run->site->name();
        $this->syncStatuses($run);
        $live = $this->releases->live($name);
        if ($live === null) {
            return;
        }
        $run->act("{$live->id} is live");
        if ($run->site->isLaravel() && Maintenance::isDown($live->dir)) {
            $this->up($run, $live);
        }

        // An interrupted G7 (or rollback step 4) may have left the domain on the old PHP.
        $recorded = $live->phpFamily() !== null && $live->phpMajorMinor() !== null ? PhpInstall::tagFor($live->phpFamily(), $live->phpMajorMinor()) : null;
        if ($run->site->syncMultiPhp() && $recorded !== null) {
            try {
                $domain = $this->domains->find($run->site->domain());
                $current = $domain !== null ? $this->php->domainPhp($domain) : null;
                if ($current !== null && $current->tag() !== $recorded) {
                    $run->warn(sprintf(
                        "The domain's PHP is %s, but the live release was built with %s — set %s in cPanel → MultiPHP Manager",
                        $current->tag(),
                        $recorded,
                        $recorded,
                    ));
                }
            } catch (Throwable $e) {
                $run->warn("Couldn't read the domain's PHP version: " . $e->getMessage());
            }
        }
        $run->check('Check that the site works: https://' . $run->site->domain());
    }

    /**
     * Makes the statuses match `current`: the release it points at is live, any
     * other `live` release becomes ready.
     */
    private function syncStatuses(RecoveryRun $run): void
    {
        $name = $run->site->name();
        $liveId = $this->releases->liveId($name);
        foreach ($this->releases->all($name) as $release) {
            if ($release->id === $liveId && $release->status() !== Release::LIVE) {
                $release->set('status', Release::LIVE);
                if ($release->get('activated_at') === null) {
                    $release->set('activated_at', $this->clock->iso());
                }
                $release->save($this->fs);
                $run->act("Marked {$release->id} live");
            } elseif ($release->id !== $liveId && $release->status() === Release::LIVE) {
                $release->set('status', Release::READY);
                $release->save($this->fs);
            }
        }
    }

    private function pointsAtRelease(RecoveryRun $run): bool
    {
        $id = $run->release();

        return $id !== null && $this->releases->liveId($run->site->name()) === $id;
    }

    /**
     * The interrupted deploy's release → failed (unless it is live).
     */
    private function markFailed(RecoveryRun $run): void
    {
        $id = $run->release();
        if ($id === null) {
            return;
        }
        $release = $this->releases->find($run->site->name(), $id);
        if ($release === null || $release->id === $this->releases->liveId($run->site->name())) {
            return;
        }
        $release->set('status', Release::FAILED);
        $release->save($this->fs);
        $run->act("Marked release {$id} failed (removed by the next cleanup)");
    }

    /**
     * `artisan up` in the release that G1 put into maintenance mode.
     */
    private function upBefore(RecoveryRun $run): void
    {
        $id = $run->string('maintenance_on');
        $release = $id !== null ? $this->releases->find($run->site->name(), $id) : null;
        if ($release !== null && Maintenance::isDown($release->dir)) {
            $this->up($run, $release);
        }
    }

    private function up(RecoveryRun $run, Release $release): void
    {
        $php = $this->phpFor($run->site, $release);
        if ($php === null) {
            $run->warn("{$release->id} is in maintenance mode and no PHP was found to run artisan up — run: cpdeploy up {$run->site->name()}");

            return;
        }
        $result = $this->maintenance->up($release->dir, $php, new RunOptions(cwd: $release->dir, timeout: 120, label: 'artisan up'));
        if ($result->successful()) {
            $run->act("Maintenance mode off in {$release->id}");
        } else {
            $run->warn("php artisan up failed in {$release->id} — run it by hand (cpdeploy up {$run->site->name()})");
        }
    }

    /**
     * MultiPHP back to `multiphp_before` when the operation had changed it.
     */
    private function revertPhp(RecoveryRun $run): void
    {
        $before = $run->string('multiphp_before');
        $after = $run->string('multiphp_after');
        if ($after === null || $before === null || $after === $before) {
            return;
        }
        try {
            $this->multiPhp->setVhostVersion($run->site->domain(), $before, 11);
            $run->act("Domain PHP set back to {$before}");
        } catch (Throwable $e) {
            $run->warn(sprintf("Couldn't set the domain's PHP back to %s: %s — set it in cPanel → MultiPHP Manager", $before, $e->getMessage()));
        }
    }

    /**
     * The docroot backup of an interrupted first conversion: site.yml's
     * domain.backup when it was recorded, else the newest backups/docroot-*.
     */
    private function docrootBackup(RecoveryRun $run): ?string
    {
        $siteDir = $this->paths->siteDir($run->site->name());
        $recorded = $run->site->get('domain.backup');
        if (is_string($recorded) && $recorded !== '' && is_dir($siteDir . '/' . $recorded)) {
            return $siteDir . '/' . $recorded;
        }
        if ($run->liveBefore() !== null) {
            return null;
        }
        $candidates = glob($this->paths->backupsDir($run->site->name()) . '/docroot-*', GLOB_ONLYDIR) ?: [];
        rsort($candidates);

        return $candidates[0] ?? null;
    }

    /**
     * The PHP a release was built with (PHP-07), else the site PHP, else null.
     */
    private function phpFor(SiteConfig $site, Release $release): ?string
    {
        $recorded = $release->phpBinary();
        if ($recorded !== null && is_executable($recorded)) {
            return $recorded;
        }
        try {
            return $this->php->resolve($site->phpVersion(), $site->phpFamily())->binary;
        } catch (Throwable) {
            return null;
        }
    }
}
