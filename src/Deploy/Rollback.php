<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Deploy\Steps\DocrootFilesStep;
use Cpdeploy\Deploy\Steps\LinkSharedStep;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Env\EnvFile;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Runtime\DomainPhp;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpLocator;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\Signals;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\Format;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Rollback (§11.8): switch `current` back to an earlier, kept release. Never
 * touches the database, .env or shared storage (RB-08).
 *
 * rollback() is the `rollback` command (RB-01…07). prepare() + run() are the
 * core, also used by a deploy whose health check failed (HC-03), which already
 * holds the lock.
 */
final class Rollback
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly GlobalConfig $config,
        private readonly SiteRegistry $sites,
        private readonly DomainService $domains,
        private readonly ReleaseManager $releases,
        private readonly PhpService $php,
        private readonly PhpLocator $locator,
        private readonly MultiPhpService $multiPhp,
        private readonly DocrootManager $docroots,
        private readonly LinkSharedStep $shared,
        private readonly DocrootFilesStep $docrootFiles,
        private readonly Artisan $artisan,
        private readonly Maintenance $maintenance,
        private readonly HealthChecker $health,
        private readonly Finisher $finisher,
        private readonly Recovery $recovery,
        private readonly Signals $signals,
        private readonly Shell $shell,
        private readonly Masker $masker,
    ) {
    }

    /**
     * `cpdeploy rollback <site> [release]` (§10.2).
     */
    public function rollback(
        string $name,
        ?string $targetId,
        bool $previous,
        bool $yes,
        bool $noHealthCheck,
        bool $recover,
        Asker $asker,
        Reporter $reporter,
    ): RollbackResult {
        $this->sites->load($name); // "no such site" before taking a lock
        $started = microtime(true);
        $lock = Lock::site($this->paths->siteLock($name), $name, 'rollback', $this->system->userName(), Version::get(), $this->clock);
        try {
            // RB-01: an interrupted operation is recovered first (it may change site.yml).
            $this->recovery->beforeOperation($name, $recover || $yes, $asker, $reporter);
            $site = $this->sites->load($name);

            $live = $this->releases->live($name);
            $target = $this->target($name, $targetId, $previous, $asker);
            $log = Log::open($this->paths->logsDir($name), 'rollback', $this->masker, $this->clock, [
                'tool' => 'cpdeploy ' . Version::get(),
                'tool php' => PHP_VERSION . ' (' . PHP_BINARY . ')',
                'site' => $name,
                'user' => $this->system->userName(),
                'host' => $this->system->hostName(),
                'target' => $target->id . ' (' . $target->short() . ')',
                'live before' => $live !== null ? $live->id . ' (' . $live->short() . ')' : 'none',
            ]);
            $this->shell->attachLog($log);
            $this->loadEnvSecrets($site);
            $job = null;
            try {
                $job = $this->prepare(
                    $site,
                    $target,
                    $live,
                    $reporter,
                    $asker,
                    $log,
                    $site->healthEnabled() && !$noHealthCheck,
                    $asker->interactive() && !$yes,
                );
                $this->confirm($job, $yes);
                $result = $this->run($job);
                $this->history($job, $result, microtime(true) - $started, $log->path);
                $log->close($result->result, $result->exitCode);

                return $result;
            } catch (Throwable $e) {
                $error = $e instanceof CpdeployException
                    ? $e
                    : new CpdeployException(ErrorCode::INTERNAL, 'Unexpected error: ' . $e->getMessage(), 'This is a bug in cpdeploy. Please report it with the log.', previous: $e);
                $log->write('ERROR ' . $error->errorCode->value . ': ' . $error->getMessage());
                if (!$e instanceof CpdeployException) {
                    $log->write((string) $e);
                }
                $resultName = $error->errorCode === ErrorCode::CANCELLED ? 'cancelled' : 'failed';
                if ($job !== null) {
                    $this->history($job, new RollbackResult(
                        $resultName,
                        $error->exitCode(),
                        $target->id,
                        $live?->id,
                        $target->commit(),
                        $live?->commit(),
                        [...$job->notes, $error->errorCode->value . ': ' . strtok($error->getMessage(), "\n")],
                    ), microtime(true) - $started, $log->path);
                }
                $log->close($resultName, $error->exitCode());

                throw $error->withLogPath($log->path);
            }
        } finally {
            $this->shell->attachLog(null);
            $lock->release();
        }
    }

    /**
     * RB-02: the release to roll back to — the given id, --previous (the newest
     * ready release older than the live one), or a picker on a TTY.
     */
    public function target(string $name, ?string $id, bool $previous, Asker $asker): Release
    {
        $liveId = $this->releases->liveId($name);
        if ($id === null || $id === '') {
            $candidates = array_values(array_filter(
                $this->releases->all($name),
                static fn (Release $r): bool => $r->id !== $liveId && $r->status() === Release::READY,
            ));
            if ($previous) {
                foreach ($candidates as $release) {
                    if ($liveId === null || strcmp($release->id, $liveId) < 0) {
                        return $this->validTarget($name, $release, $liveId);
                    }
                }
                throw new CpdeployException(ErrorCode::ROLLBACK, "{$name} has no earlier release to roll back to", "List the releases: cpdeploy releases {$name}");
            }
            if (!$asker->interactive()) {
                throw new CpdeployException(ErrorCode::USAGE, 'Which release? Pass its id or --previous', "List them with: cpdeploy releases {$name}");
            }
            if ($candidates === []) {
                throw new CpdeployException(ErrorCode::ROLLBACK, "{$name} has no other release to roll back to", "List the releases: cpdeploy releases {$name}");
            }
            $options = [];
            $now = $this->clock->now();
            foreach ($candidates as $release) {
                $options[$release->id] = implode(' · ', array_filter([
                    $release->id,
                    $release->short(),
                    Format::truncate($release->message(), 40),
                    'deployed ' . $this->when($release->get('activated_at') ?? $release->createdAt(), $now),
                    $release->phpMajorMinor() !== null ? 'PHP ' . $release->phpMajorMinor() : null,
                ], static fn (?string $s): bool => $s !== null && $s !== ''));
            }
            $id = (string) $asker->select('Roll back to which release?', $options, array_key_first($options));
        }

        return $this->validTarget($name, $this->releases->get($name, $id), $liveId);
    }

    /**
     * RB-03 and RB-04: the target's PHP, how the domain's PHP will change, and
     * the risks to show before confirming.
     */
    public function prepare(
        SiteConfig $site,
        Release $target,
        ?Release $live,
        Reporter $reporter,
        Asker $asker,
        ?Log $log,
        bool $healthCheck,
        bool $offerSwitchBack,
    ): RollbackJob {
        $job = new RollbackJob($site, $target, $live, $this->targetPhp($target), $reporter, $asker, $log, $healthCheck, $offerSwitchBack, (float) $this->config->timeout('artisan'));
        $this->domainPhp($job);

        // Migrations recorded in newer releases stay in the database.
        $newer = [];
        foreach ($this->releases->all($site->name()) as $release) {
            if (strcmp($release->id, $target->id) > 0 && $release->get('migrations.ran') === true) {
                $list = $release->get('migrations.list');
                foreach (is_array($list) ? $list : [] as $migration) {
                    if (is_string($migration)) {
                        $newer[$migration] = true;
                    }
                }
            }
        }
        if ($newer !== []) {
            $job->risks[] = 'These database changes stay in place: ' . implode(', ', array_keys($newer)) . '. The older code may not work with them.';
        }

        $targetTag = $job->php->tag();
        if ($job->domainPhpTag !== null && $job->domainPhpTag !== $targetTag) {
            $job->risks[] = $job->phpChange !== PhpService::CHANGE_NONE
                ? sprintf('The domain will switch to PHP %s (%s now)', $job->php->majorMinor(), $job->domainPhpTag)
                : sprintf('The domain stays on %s (the release was built with PHP %s)', $job->domainPhpTag, $job->php->majorMinor());
        }

        $env = $this->paths->sharedEnv($site->name());
        $created = $target->createdAt();
        $createdAt = $created !== null ? strtotime($created) : false;
        if ($site->isLaravel() && is_file($env) && $createdAt !== false && (int) filemtime($env) > $createdAt) {
            $job->risks[] = ".env changed since {$target->id} was built: optimize will rebuild its config cache";
        }

        return $job;
    }

    /**
     * RB-06 with the state file (§8.7 rollback phases). The lock is held and no
     * state file exists. Throws E_ROLLBACK when nothing was switched.
     */
    public function run(RollbackJob $job): RollbackResult
    {
        $name = $job->name();
        $target = $job->target;
        $live = $job->live;
        $state = new StateFile($this->paths->stateFile($name), $this->fs);
        $state->begin(StateFile::ROLLBACK, $this->clock->iso(), [
            'phase' => StateFile::PREPARING,
            'release' => $target->id,
            'live_before' => $live?->id,
        ]);
        $job->log?->write(sprintf('Rollback of %s: %s → %s', $name, $live->id ?? 'none', $target->id));
        $track = ['multiphp' => false, 'phpAfter' => false];

        try {
            $this->relink($job);   // 1
            $this->optimize($job); // 2
            $this->up($job);       // 3

            $result = DeployResult::SUCCESS;
            $this->signals->critical(function () use ($job, $state, &$track, &$result): void {
                $this->phpBefore($job, $state, $track);   // 4 (upgrade / family)
                $this->switch($job, $state, $track);      // 5, 6
                if (!$this->phpAfter($job, $state, $track)) { // 4 (downgrade)
                    $result = DeployResult::WARNING;
                }
            });

            if ($track['multiphp'] || $track['phpAfter']) {
                try {
                    $this->docroots->captureHandler($job->site, $target->webPath($job->site->webDir()));
                } catch (Throwable $e) {
                    $job->warn("Couldn't capture the PHP handler block: " . $e->getMessage());
                }
            }

            $state->phase(StateFile::FINISHING);
            $health = $this->healthCheck($job);   // 7
            $exit = 0;
            $message = '';
            if ($health !== null && !$health->ok) {
                $status = $health->status > 0 ? (string) $health->status : 'no response';
                if ($job->offerSwitchBack && $live !== null && $this->askSwitchBack($job, $health)) {
                    $this->switchBack($job, $state, $track);
                    $state->delete();
                    throw new CpdeployException(
                        ErrorCode::ROLLBACK,
                        sprintf('Rollback to %s failed: %s returned %s — switched back to %s', $target->id, $health->url, $status, $live->id),
                        'See the log. The site is on the release it was on before.',
                    );
                }
                $message = sprintf('%s returned %s after the rollback to %s', $health->url, $status, $target->id);
                $result = DeployResult::WARNING;
                $exit = 7;
            }
            if ($result === DeployResult::SUCCESS && $job->warnings !== []) {
                $result = DeployResult::WARNING;
            }
            $state->delete();
            $job->reporter->info(sprintf('Rolled back to %s (%s) · https://%s', $target->id, $target->short(), $job->site->domain()));

            return new RollbackResult($result, $exit, $target->id, $live?->id, $target->commit(), $live?->commit(), $job->notes, $job->warnings, $message, $health);
        } catch (CpdeployException $e) {
            // A handled failure (LCK-03): what changed was undone before the throw.
            $state->delete();
            throw $e;
        }
    }

    /**
     * RB-05: confirmation, after the risks (RB-04) are shown.
     */
    private function confirm(RollbackJob $job, bool $yes): void
    {
        $target = $job->target;
        $job->reporter->info(sprintf(
            'Roll back %s: %s → %s (%s "%s")',
            $job->name(),
            $job->live->id ?? 'none',
            $target->id,
            $target->short(),
            Format::truncate($target->message(), 50),
        ));
        $job->showRisks();
        if ($yes) {
            return;
        }
        if (!$job->asker->interactive()) {
            throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'This needs answers: --yes (to confirm the rollback)', 'Run again with --yes.');
        }
        if (!$job->asker->confirm(sprintf('Roll back %s to %s?', $job->site->domain(), $target->id), true)) {
            throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — your live site was not changed', '');
        }
    }

    /**
     * RB-02 checks: ready, not live, has its web dir (and vendor/ for Laravel).
     */
    private function validTarget(string $name, Release $release, ?string $liveId): Release
    {
        $site = $this->sites->load($name);
        $problem = match (true) {
            $release->id === $liveId => 'it is already live',
            $release->status() !== Release::READY => 'its status is ' . $release->status() . ' (only ready releases can go live)',
            !is_dir($release->webPath($site->webDir())) => sprintf("it has no '%s' folder", $site->webDir() === '' ? '.' : $site->webDir()),
            $site->isLaravel() && !is_dir($release->dir . '/vendor') => 'it has no vendor/ folder',
            default => null,
        };
        if ($problem !== null) {
            throw new CpdeployException(ErrorCode::ROLLBACK, "Can't roll back to {$release->id}: {$problem}", "List the releases: cpdeploy releases {$name}");
        }

        return $release;
    }

    /**
     * RB-03: the PHP recorded in the target's .release.json.
     */
    private function targetPhp(Release $target): PhpInstall
    {
        $binary = $target->phpBinary();
        $version = $target->get('php.version');
        $family = $target->phpFamily() ?? PhpInstall::EA;
        if ($binary === null || !is_string($version) || !is_executable($binary)) {
            throw new CpdeployException(
                ErrorCode::PHP_MISSING,
                sprintf(
                    "PHP %s, which %s was built with, isn't installed on this server any more. Installed: %s",
                    is_string($version) ? PhpInstall::majorMinorOf($version) : '(unknown)',
                    $target->id,
                    $this->locator->describe(),
                ),
                'Roll back to a release built with an installed PHP, or deploy again.',
            );
        }

        return new PhpInstall($family, $version, $binary);
    }

    /**
     * GL-03 input for the target's PHP. Only with php.sync_multiphp, and never for
     * a domain on PHP Selector with alt-php (PHP-06 option b).
     */
    private function domainPhp(RollbackJob $job): void
    {
        $site = $job->site;
        try {
            $job->domain = $this->domains->find($site->domain());
            $current = $job->domain !== null ? $this->php->domainPhp($job->domain) : null;
        } catch (Throwable $e) {
            $job->warn("Couldn't read the domain's PHP version: " . $e->getMessage());

            return;
        }
        if ($current === null) {
            return;
        }
        $job->domainPhpTag = $current->tag();
        if (!$site->syncMultiPhp() || ($job->php->family === PhpInstall::ALT && $current->source === DomainPhp::SOURCE_SELECTOR)) {
            return;
        }
        $job->phpChange = PhpService::phpChange($current->tag(), $job->php->tag());
    }

    /**
     * Step 1: re-link the target's shared paths (REL-04) and docroot extras, and
     * inject the handler block for the target's PHP (DOC-04).
     */
    private function relink(RollbackJob $job): void
    {
        $job->reporter->start('Shared files');
        try {
            $linked = $this->shared->linkRelease($job->site, $job->target->dir);
            $web = $job->target->webPath($job->site->webDir());
            $extras = $this->docrootFiles->linkWeb($job->site, $web, $job->php->family, $job->php->majorMinor(), $job->log);
        } catch (CpdeployException $e) {
            $job->reporter->fail($e->getMessage(), $job->log?->path);
            throw new CpdeployException(ErrorCode::ROLLBACK, "Rollback to {$job->target->id} failed: " . $e->getMessage(), 'See the log. Nothing was switched.');
        }
        $job->reporter->succeed(implode(' · ', array_filter([LinkSharedStep::summary($linked), implode(' · ', $extras)])));
    }

    /**
     * Step 2: `artisan optimize` in the target with the target's PHP. A failure
     * aborts the rollback before anything changed (exit 8).
     */
    private function optimize(RollbackJob $job): void
    {
        $site = $job->site;
        if (!$site->isLaravel() || $site->step('optimize') === 'off' || !is_file($job->target->dir . '/artisan')) {
            return;
        }
        $job->reporter->start('optimize');
        $result = $this->artisan->run($job->target->dir, $job->php->binary, ['optimize'], $job->options($job->target->dir, 'artisan optimize'));
        if (!$result->successful()) {
            $job->reporter->fail('php artisan optimize failed', $job->log?->path);
            throw new CpdeployException(
                ErrorCode::ROLLBACK,
                "Rollback to {$job->target->id} failed: php artisan optimize failed with PHP {$job->php->majorMinor()}",
                'See the log. Nothing was switched; the site is still on ' . ($job->live->id ?? 'its current release') . '.',
            );
        }
        $job->reporter->succeed('PHP ' . $job->php->majorMinor());
    }

    /**
     * Step 3: the target may still be in maintenance mode from the deploy that
     * replaced it (GL-02).
     */
    private function up(RollbackJob $job): void
    {
        if (!$job->site->isLaravel() || !Maintenance::isDown($job->target->dir)) {
            return;
        }
        $job->reporter->start('Maintenance off');
        $result = $this->maintenance->up($job->target->dir, $job->php->binary, $job->options($job->target->dir, 'artisan up'));
        if (!$result->successful()) {
            $job->reporter->succeed('(artisan up reported a problem; see the log)');
            $job->warn("php artisan up failed in {$job->target->id}: run it by hand (cpdeploy up {$job->name()})");

            return;
        }
        $job->reporter->succeed('(' . $job->target->id . ')');
    }

    /**
     * Step 4 before the switch: upgrades and ea ↔ alt change the domain's PHP first (GL-03).
     *
     * @param array{multiphp: bool, phpAfter: bool} $track
     */
    private function phpBefore(RollbackJob $job, StateFile $state, array &$track): void
    {
        if (!PhpService::switchesBeforeCode($job->phpChange)) {
            return;
        }
        $tag = $job->php->tag();
        $state->update(['phase' => StateFile::MULTIPHP, 'multiphp_before' => $job->domainPhpTag]);
        $job->reporter->start('PHP version');
        try {
            $this->docroots->ensureHtaccess($this->docroots->servedFolder($job->site));
            $this->multiPhp->setVhostVersion($job->site->domain(), $tag, 8);
        } catch (Throwable $e) {
            $job->reporter->fail($e->getMessage(), $job->log?->path);
            throw new CpdeployException(
                ErrorCode::ROLLBACK,
                sprintf("Rollback to %s failed: couldn't set PHP %s for %s: %s", $job->target->id, $tag, $job->site->domain(), $e->getMessage()),
                'cPanel → MultiPHP Manager. Nothing was switched.',
            );
        }
        $track['multiphp'] = true;
        $state->update(['multiphp_after' => $tag]);
        $job->reporter->succeed(sprintf('%s → %s', $job->domainPhpTag ?? '?', $tag));
    }

    /**
     * Steps 5 and 6: `current` → target (FS-02), then the statuses.
     *
     * @param array{multiphp: bool, phpAfter: bool} $track
     */
    private function switch(RollbackJob $job, StateFile $state, array $track): void
    {
        $state->phase(StateFile::SWITCHING);
        $job->reporter->start('Go live');
        try {
            $this->fs->swapSymlink($this->paths->current($job->name()), 'releases/' . $job->target->id);
        } catch (RuntimeException $e) {
            $job->reporter->fail($e->getMessage(), $job->log?->path);
            $this->revertPhp($job, $track);
            throw new CpdeployException(ErrorCode::ROLLBACK, "Rollback to {$job->target->id} failed: " . $e->getMessage(), 'See the log. Nothing was switched.');
        }
        $state->update(['phase' => StateFile::SWITCHED, 'switched' => true]);
        $this->releases->markLive($job->target, $job->live);
        $job->reporter->succeed('current → ' . $job->target->id);
    }

    /**
     * Step 4 after the switch: downgrades change the domain's PHP after the code
     * (GL-03). A failure is a warning, not a rollback of the rollback.
     *
     * @param array{multiphp: bool, phpAfter: bool} $track
     */
    private function phpAfter(RollbackJob $job, StateFile $state, array &$track): bool
    {
        if ($job->phpChange !== PhpService::CHANGE_DOWNGRADE) {
            return true;
        }
        $tag = $job->php->tag();
        $state->update(['phase' => StateFile::MULTIPHP, 'multiphp_before' => $job->domainPhpTag]);
        $job->reporter->start('PHP version');
        try {
            $this->docroots->ensureHtaccess($job->target->webPath($job->site->webDir()));
            $this->multiPhp->setVhostVersion($job->site->domain(), $tag, 8);
        } catch (Throwable $e) {
            $job->reporter->fail($e->getMessage(), $job->log?->path);
            $job->warn(sprintf(
                "The site is on %s, but the domain's PHP is still %s — set it to %s in cPanel → MultiPHP Manager",
                $job->target->id,
                $job->domainPhpTag ?? 'unchanged',
                $tag,
            ));
            $state->phase(StateFile::SWITCHED);

            return false;
        }
        $track['phpAfter'] = true;
        $state->update(['multiphp_after' => $tag, 'phase' => StateFile::SWITCHED]);
        $job->reporter->succeed(sprintf('%s → %s', $job->domainPhpTag ?? '?', $tag));

        return true;
    }

    /**
     * Step 7 (§11.6): the release marker (HC-01, written for this check and
     * deleted afterwards) and the health request.
     */
    private function healthCheck(RollbackJob $job): ?HealthResult
    {
        if (!$job->healthCheck) {
            return null;
        }
        $web = $job->target->webPath($job->site->webDir());
        $marker = '.cpd-release-' . bin2hex(random_bytes(16)) . '.txt';
        try {
            try {
                $this->fs->writeAtomic($web . '/' . $marker, $job->target->id, Paths::MODE_PUBLIC_FILE);
                $warning = $this->health->marker($job->site, $job->site->ip(), $marker, $job->target->id);
                if ($warning !== null) {
                    $job->warn(str_replace('new release', 'release it rolled back to', $warning));
                }
            } catch (RuntimeException $e) {
                $job->log?->write("Release marker not written: {$e->getMessage()}");
            }
            $job->reporter->start('Health check');
            $result = $this->health->check($job->site, $job->site->ip(), false, fn (string $line) => $job->reporter->line($line));
            foreach ($result->warnings as $warning) {
                $job->warn($warning);
            }
            $job->note($result->note());
            if ($result->ok) {
                $job->reporter->succeed(sprintf('GET %s → %d in %.1fs', $job->site->healthPath(), $result->status, $result->seconds));
            } else {
                $job->reporter->fail(sprintf('%s returned %s (%d attempts)', $result->url, $result->status > 0 ? (string) $result->status : 'no response', $result->attempts), $job->log?->path);
            }

            return $result;
        } finally {
            if (is_file($web . '/' . $marker)) {
                @unlink($web . '/' . $marker);
            }
        }
    }

    private function askSwitchBack(RollbackJob $job, HealthResult $health): bool
    {
        $live = $job->live ?? throw new \LogicException('No release to switch back to');

        return $job->asker->select(
            sprintf('%s returned %s after the rollback.', $health->url, $health->status > 0 ? (string) $health->status : 'no response'),
            ['back' => "Switch back to {$live->id} ({$live->short()})", 'keep' => "Keep {$job->target->id}"],
            'back',
        ) === 'back';
    }

    /**
     * RB-06 step 7: the health check failed and the user chose to go back.
     *
     * @param array{multiphp: bool, phpAfter: bool} $track
     */
    private function switchBack(RollbackJob $job, StateFile $state, array $track): void
    {
        $live = $job->live ?? throw new \LogicException('No release to switch back to');
        $this->signals->critical(function () use ($job, $state, $track, $live): void {
            $state->phase(StateFile::SWITCHING);
            $job->reporter->start('Switch back');
            $this->fs->swapSymlink($this->paths->current($job->name()), 'releases/' . $live->id);
            $this->releases->markLive($live, $job->target);
            $state->update(['phase' => StateFile::SWITCHED, 'release' => $live->id, 'live_before' => $job->target->id]);
            $job->reporter->succeed('current → ' . $live->id);
            $this->revertPhp($job, ['multiphp' => $track['multiphp'] || $track['phpAfter'], 'phpAfter' => false]);
        });
        $job->note("switched back to {$live->id} after the health check failed");
    }

    /**
     * @param array{multiphp: bool, phpAfter: bool} $track
     */
    private function revertPhp(RollbackJob $job, array $track): void
    {
        if (!$track['multiphp'] || $job->domainPhpTag === null) {
            return;
        }
        try {
            $this->multiPhp->setVhostVersion($job->site->domain(), $job->domainPhpTag, 8);
        } catch (Throwable $e) {
            $job->warn(sprintf("Couldn't set the domain's PHP back to %s: %s — set it in cPanel → MultiPHP Manager", $job->domainPhpTag, $e->getMessage()));
        }
    }

    /**
     * RB-07: one history entry.
     */
    public function history(RollbackJob $job, RollbackResult $result, float $duration, ?string $log): void
    {
        $this->finisher->history(
            $job->name(),
            'rollback',
            $result->result,
            $result->exitCode,
            $result->target,
            $result->from,
            $result->targetCommit,
            $result->fromCommit,
            $duration,
            $log,
            $this->system->userName(),
            array_values(array_unique($result->notes)),
        );
    }

    /**
     * LOG-04: the site's .env secrets are masked in the rollback log.
     */
    private function loadEnvSecrets(SiteConfig $site): void
    {
        try {
            $file = $this->paths->sharedEnv($site->name());
            if (is_file($file)) {
                $this->masker->addEnv(EnvFile::fromFile($file)->all());
            }
        } catch (Throwable) {
            // A broken .env doesn't stop a rollback (RB-08: it isn't touched).
        }
    }

    private function when(mixed $iso, DateTimeImmutable $now): string
    {
        if (!is_string($iso) || $iso === '') {
            return '—';
        }
        try {
            return Format::relative(new DateTimeImmutable($iso), $now);
        } catch (\Exception) {
            return $iso;
        }
    }
}
