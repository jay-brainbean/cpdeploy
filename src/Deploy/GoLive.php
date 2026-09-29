<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\CustomCommand;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Deploy\Steps\CustomCommandStep;
use Cpdeploy\Deploy\Steps\QueueRestartStep;
use Cpdeploy\Deploy\Steps\SeedStep;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Laravel\MigrationStatus;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\Signals;
use Cpdeploy\Ui\Format;
use RuntimeException;
use Throwable;

/**
 * Phase C (§11.5): maintenance on → migrate → seed → MultiPHP → SWITCH → docroot
 * → MultiPHP → maintenance off → after-activate → health.
 *
 * G1–G8 run as one critical section (GL-01): signals are deferred. Every step
 * that can affect the live site is written to the state file before it starts
 * (INV-08). A failure before the switch, or in G6, leaves `current` where it was
 * (INV-09) and brings L back up.
 */
final class GoLive
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly Signals $signals,
        private readonly Clock $clock,
        private readonly Maintenance $maintenance,
        private readonly MigrationStatus $migrations,
        private readonly MultiPhpService $multiPhp,
        private readonly DocrootManager $docroots,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly PhpService $php,
        private readonly StepRunner $runner,
        private readonly SeedStep $seed,
        private readonly QueueRestartStep $queueRestart,
        private readonly HealthChecker $health,
    ) {
    }

    /**
     * Whether G2 runs (PLN-03, PLN-08, PLN-09).
     */
    public static function willMigrate(DeployContext $ctx): bool
    {
        $plan = $ctx->plan();
        $check = $ctx->pendingAfterBuild;

        return match ($plan->migrate) {
            DeployPlan::MIGRATE_YES => $check === null || !$check->known || $check->pending !== [],
            DeployPlan::MIGRATE_AUTO => $check !== null && (!$check->known || $check->pending !== []),
            default => false,
        };
    }

    public function run(DeployContext $ctx): GoLiveResult
    {
        $track = [
            'maintenance' => false,  // G1 ran
            'migrated' => false,     // G2 started
            'multiphp' => false,     // G4 ran
            'switched' => false,     // G5 ran
            'converted' => false,    // G6 conversion
            'backup' => null,        // G6 backup path
            'phpAfter' => false,     // G7 ran
            'maintenanceFrom' => null,
            'maintenanceSeconds' => null,
            'ran' => [],
        ];
        $result = DeployResult::SUCCESS;

        if ($ctx->plan()->migrate === DeployPlan::MIGRATE_YES && $ctx->pendingAfterBuild !== null && $ctx->pendingAfterBuild->known && $ctx->pendingAfterBuild->pending === []) {
            $ctx->note('migrations: nothing to migrate');
        }

        $this->signals->critical(function () use ($ctx, &$track, &$result): void {
            $this->maintenanceOn($ctx, $track);   // G1
            $this->migrate($ctx, $track);         // G2, G3
            $this->phpBefore($ctx, $track);       // G4
            $this->switch($ctx, $track);          // G5
            $this->docroot($ctx, $track);         // G6
            $ctx->state?->phase(StateFile::SWITCHED);
            if (!$this->phpAfter($ctx, $track)) { // G7
                $result = DeployResult::WARNING;
            }
            $this->maintenanceOff($ctx, $track);  // G8
        });

        // G9: keep the handler block cPanel wrote for the new version.
        if ($track['multiphp'] || $track['phpAfter']) {
            try {
                $this->docroots->captureHandler($ctx->site, (string) $ctx->release?->webPath($ctx->site->webDir()));
            } catch (Throwable $e) {
                $ctx->warn("Couldn't capture the PHP handler block: " . $e->getMessage());
            }
        }

        // G10: after-activate. Failures never roll back: the code is already live.
        foreach ([$this->queueRestart, ...array_map(fn (CustomCommand $c): CustomCommandStep => new CustomCommandStep($this->shell, $c), $ctx->site->customCommands(CustomCommand::AFTER))] as $step) {
            try {
                $this->runner->run($ctx, $step);
            } catch (CpdeployException $e) {
                if ($e->errorCode === ErrorCode::CANCELLED) {
                    throw $e;
                }
                $ctx->warn($e->getMessage() . ' (the new release is live; nothing was rolled back)');
                $result = DeployResult::FAILED;
            }
        }
        $health = $this->healthCheck($ctx, (bool) $track['maintenance']);
        // §8.6: completed, with warnings (including the health check's).
        if ($result === DeployResult::SUCCESS && $ctx->warnings !== []) {
            $result = DeployResult::WARNING;
        }

        return new GoLiveResult($result, $health, $track['ran'], (bool) $track['migrated'], $track['maintenanceSeconds']);
    }

    /**
     * G1: `artisan down` in L with L's PHP (PHP-07), only when migrations will run.
     *
     * @param array<string, mixed> $track
     */
    private function maintenanceOn(DeployContext $ctx, array &$track): void
    {
        $live = $ctx->live;
        if ($live === null || !self::willMigrate($ctx) || $ctx->site->step('maintenance') !== 'with_migrations') {
            return;
        }
        $ctx->state?->update(['phase' => StateFile::MAINTENANCE, 'maintenance_on' => $live->id]);
        $ctx->reporter->start('Maintenance on');
        $options = $ctx->options($ctx->timeout('artisan'), 'artisan down', cwd: $live->dir);
        $result = $this->maintenance->down($live->dir, $this->livePhp($ctx), $ctx->site->maintenanceOptions(), $ctx->site->maintenanceSecret(), $options);
        if (!$result->successful()) {
            $ctx->reporter->fail('php artisan down failed', $ctx->log?->path);
            throw new CpdeployException(
                ErrorCode::GOLIVE,
                "Couldn't turn on maintenance mode in the live release ({$live->id}); nothing was changed",
                'See the log. The new release is built and kept; deploy again once artisan down works.',
            );
        }
        $track['maintenance'] = true;
        $track['maintenanceFrom'] = microtime(true);
        $ctx->reporter->succeed('(live release)');
    }

    /**
     * G2 (+ G3 seed): `artisan migrate --force` in N. On failure L comes back up
     * and N is marked failed (exit 5).
     *
     * @param array<string, mixed> $track
     */
    private function migrate(DeployContext $ctx, array &$track): void
    {
        $release = $ctx->release ?? throw new \LogicException('No release');
        $php = $ctx->sitePhp();
        if (self::willMigrate($ctx)) {
            $before = $ctx->pendingAfterBuild !== null && $ctx->pendingAfterBuild->known ? $ctx->pendingAfterBuild->pending : null;
            $ctx->state?->update(['phase' => StateFile::MIGRATING, 'migrations_started' => true]);
            $track['migrated'] = true;
            $ctx->reporter->start('Migrations');
            $options = $this->criticalOptions($ctx, $ctx->timeout('migrate'), 'artisan migrate');
            $result = $this->shell->run([$php->binary, 'artisan', 'migrate', '--force', '--no-interaction', '--ansi'], $options);
            if (!$result->successful()) {
                $ctx->reporter->fail('Migration failed', $ctx->log?->path);
                $completed = [];
                if ($before !== null) {
                    $after = $this->migrations->pending($release->dir, $php->binary, $ctx->info()->laravelMajor(), new RunOptions(timeout: $ctx->timeout('artisan'), pathPrefix: $ctx->pathPrefix()));
                    $completed = $after->known ? array_values(array_diff($before, $after->pending)) : [];
                }
                $release->set('migrations', ['ran' => true, 'list' => $completed]);
                throw $this->migrationFailed($ctx, $track, 'Migration failed', $completed, $result->timedOut ? 'It took longer than timeouts.migrate.' : '');
            }
            $track['ran'] = $before ?? [];
            $release->set('migrations', ['ran' => true, 'list' => $before ?? []]);
            $ctx->reporter->succeed($before !== null ? count($before) . ' ran' : 'done');
            $ctx->note('migrations: ' . ($before !== null ? count($before) . ' ran' : 'ran (list unknown)'));
        }

        if ($ctx->plan()->seed) {
            try {
                $this->runner->run($ctx, $this->seed);
            } catch (CpdeployException $e) {
                if ($e->errorCode === ErrorCode::CANCELLED) {
                    throw $e;
                }
                throw $this->migrationFailed($ctx, $track, 'db:seed failed', $track['ran'], '');
            }
        }
    }

    /**
     * @param array<string, mixed> $track
     * @param list<string> $completed
     */
    private function migrationFailed(DeployContext $ctx, array $track, string $what, array $completed, string $extra): CpdeployException
    {
        $live = $ctx->live;
        if ($track['maintenance'] && $live !== null) {
            $this->up($ctx, $live->dir, $this->livePhp($ctx));
        }
        $this->markFailed($ctx);
        $message = $live !== null
            ? sprintf('%s — the site is back up on the previous release (%s).', $what, $live->short())
            : sprintf('%s — this was the first deploy, so the site was not changed.', $what);
        if ($completed !== []) {
            $message .= ' These migrations completed before the failure: ' . implode(', ', $completed) . '.';
        }
        $message .= ' The database may need attention before the next deploy.' . ($extra !== '' ? ' ' . $extra : '');

        return new CpdeployException(ErrorCode::MIGRATE, $message, 'Fix the migration; check php artisan migrate:status.', liveAffected: true);
    }

    /**
     * G4: upgrades (and ea ↔ alt) switch the domain's PHP before the code (GL-03).
     *
     * @param array<string, mixed> $track
     */
    private function phpBefore(DeployContext $ctx, array &$track): void
    {
        if (!PhpService::switchesBeforeCode($ctx->phpChange)) {
            return;
        }
        $served = $this->docroots->servedFolder($ctx->site);
        if (!is_dir($served)) {
            return; // Nothing is served yet: G7 sets it after the switch.
        }
        $tag = $ctx->sitePhp()->tag();
        $ctx->state?->update(['phase' => StateFile::MULTIPHP, 'multiphp_before' => $ctx->domainPhpTag]);
        $ctx->reporter->start('PHP version');
        try {
            $this->docroots->ensureHtaccess($served);
            $this->multiPhp->setVhostVersion($ctx->site->domain(), $tag, 6);
        } catch (Throwable $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            $this->bringLiveUp($ctx, $track);
            $this->markFailed($ctx);
            throw new CpdeployException(
                ErrorCode::MULTIPHP,
                sprintf("Couldn't set PHP %s for %s: %s%s", $tag, $ctx->site->domain(), $e->getMessage(), $track['migrated'] ? ' Migrations already ran.' : ''),
                'cPanel → MultiPHP Manager. The site is still on the previous release.',
                liveAffected: (bool) $track['migrated'],
            );
        }
        $track['multiphp'] = true;
        $ctx->state?->update(['multiphp_after' => $tag]);
        $ctx->reporter->succeed(sprintf('%s → %s', $ctx->domainPhpTag ?? '?', $tag));
    }

    /**
     * G5: `current` → N, atomically (FS-02). The only moment the site changes.
     *
     * @param array<string, mixed> $track
     */
    private function switch(DeployContext $ctx, array &$track): void
    {
        $release = $ctx->release ?? throw new \LogicException('No release');
        $ctx->state?->phase(StateFile::SWITCHING);
        $ctx->reporter->start('Go live');
        try {
            $this->fs->swapSymlink($ctx->paths->current($ctx->name()), 'releases/' . $release->id);
        } catch (RuntimeException $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            $this->revertPhp($ctx, $track);
            $this->bringLiveUp($ctx, $track);
            $this->markFailed($ctx);
            throw new CpdeployException(ErrorCode::GOLIVE, "Couldn't switch the site to the new release: {$e->getMessage()}", 'See the log; the site is still on ' . ($ctx->live->id ?? 'its old files') . '.', liveAffected: (bool) $track['migrated']);
        }
        $track['switched'] = true;
        $ctx->state?->update(['switched' => true]);
        $this->releases->markLive($release, $ctx->live);
        $ctx->reporter->succeed('current → ' . $release->id);
    }

    /**
     * G6: first go-live converts the docroot (DOC-02) after a DOC-01 re-check;
     * later, a changed web_dir re-points it (DOC-08). A failure undoes G5.
     *
     * @param array<string, mixed> $track
     */
    private function docroot(DeployContext $ctx, array &$track): void
    {
        $site = $ctx->site;
        $convert = !$this->docroots->isConverted($site);
        if (!$convert && !$this->docroots->needsRepoint($site)) {
            return;
        }
        $ctx->state?->phase(StateFile::CONVERTING_DOCROOT);
        $ctx->reporter->start($convert ? 'Docroot' : 'Docroot (web dir changed)');
        try {
            if ($convert) {
                $this->docroots->assertSafe($site, $ctx->domains, 6);
                $notices = [];
                $backup = $this->docroots->convert($site, $this->clock->stamp(), $notices);
                $track['converted'] = true;
                $track['backup'] = $backup;
                $ctx->state?->update(['docroot_converted' => true]);
                foreach ($notices as $notice) {
                    $ctx->warn($notice);
                }
                $site = $site->with('domain.converted_at', $this->clock->iso())->with('domain.backup', $backup);
                $this->sites->save($site);
                $ctx->site = $site;
                $ctx->reporter->succeed($backup !== null ? "{$site->docroot()} → symlink (old files in {$backup})" : "{$site->docroot()} → symlink");
            } else {
                $this->docroots->repoint($site);
                $ctx->reporter->succeed('→ current/' . $site->webDir());
            }
        } catch (Throwable $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            $this->undoSwitch($ctx);
            if ($track['converted']) {
                $this->docroots->undoConversion($ctx->site, is_string($track['backup']) ? $track['backup'] : null);
            }
            $this->revertPhp($ctx, $track);
            $this->bringLiveUp($ctx, $track);
            $this->markFailed($ctx);
            $code = $e instanceof CpdeployException && in_array($e->errorCode, [ErrorCode::DOCROOT_UNSAFE, ErrorCode::DOCROOT_MOVE], true) ? $e->errorCode : ErrorCode::GOLIVE;
            throw new CpdeployException(
                $code,
                "Couldn't switch the site to the new release: " . $e->getMessage(),
                $ctx->live !== null ? "See the log; the site is still on {$ctx->live->id}." : 'See the log; the site was not changed.',
                liveAffected: (bool) $track['migrated'],
                exitCode: 6,
            );
        }
    }

    /**
     * G7: downgrades switch the domain's PHP after the code (GL-03). Also runs when
     * G4 had nothing served yet. A failure is only a warning.
     *
     * @param array<string, mixed> $track
     */
    private function phpAfter(DeployContext $ctx, array &$track): bool
    {
        $needed = $ctx->phpChange === PhpService::CHANGE_DOWNGRADE
            || (PhpService::switchesBeforeCode($ctx->phpChange) && !$track['multiphp']);
        if (!$needed) {
            return true;
        }
        $tag = $ctx->sitePhp()->tag();
        $ctx->state?->update(['phase' => StateFile::MULTIPHP, 'multiphp_before' => $ctx->domainPhpTag]);
        $ctx->reporter->start('PHP version');
        try {
            $this->docroots->ensureHtaccess((string) $ctx->release?->webPath($ctx->site->webDir()));
            $this->multiPhp->setVhostVersion($ctx->site->domain(), $tag, 6);
        } catch (Throwable $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            $ctx->warn(sprintf(
                "Site is live on the new release but the domain's PHP is still %s — set it in cPanel → MultiPHP Manager",
                $ctx->domainPhpTag ?? 'unchanged',
            ));
            $ctx->state?->phase(StateFile::SWITCHED);

            return false;
        }
        $track['phpAfter'] = true;
        $ctx->state?->update(['multiphp_after' => $tag, 'phase' => StateFile::SWITCHED]);
        $ctx->reporter->succeed(sprintf('%s → %s', $ctx->domainPhpTag ?? '?', $tag));

        return true;
    }

    /**
     * G8: `artisan up` in N. L stays in maintenance on purpose (GL-02).
     *
     * @param array<string, mixed> $track
     */
    private function maintenanceOff(DeployContext $ctx, array &$track): void
    {
        $cacheDriver = strtolower($ctx->env['APP_MAINTENANCE_DRIVER'] ?? '') === 'cache';
        if (!$track['maintenance'] && !($cacheDriver && $ctx->site->isLaravel())) {
            return;
        }
        $ctx->reporter->start('Maintenance off');
        $release = $ctx->release ?? throw new \LogicException('No release');
        $result = $this->maintenance->up($release->dir, $ctx->sitePhp()->binary, $ctx->options($ctx->timeout('artisan'), 'artisan up'));
        if (is_float($track['maintenanceFrom'])) {
            $track['maintenanceSeconds'] = microtime(true) - $track['maintenanceFrom'];
            // GL-04: how long visitors saw the maintenance page.
            $ctx->log?->write(sprintf('Maintenance mode was on for %s', Format::duration($track['maintenanceSeconds'])));
        }
        if (!$result->successful()) {
            $ctx->reporter->succeed('(artisan up reported a problem; see the log)');

            return;
        }
        $ctx->reporter->succeed('');
    }

    /**
     * G11 + HC-01. The marker file is deleted afterwards in every case.
     */
    private function healthCheck(DeployContext $ctx, bool $maintenanceWasOn): ?HealthResult
    {
        $release = $ctx->release;
        $marker = $release?->get('marker');
        $markerPath = $release !== null && is_string($marker) ? $release->webPath($ctx->site->webDir()) . '/' . $marker : null;
        try {
            if (!$ctx->plan()->healthCheck || $release === null) {
                return null;
            }
            if (is_string($marker)) {
                $warning = $this->health->marker($ctx->site, $ctx->site->ip(), $marker, $release->id);
                if ($warning !== null) {
                    $ctx->warn($warning);
                }
            }
            $ctx->reporter->start('Health check');
            $result = $this->health->check($ctx->site, $ctx->site->ip(), $maintenanceWasOn, fn (string $line) => $ctx->reporter->line($line));
            foreach ($result->warnings as $warning) {
                $ctx->warn($warning);
            }
            $ctx->note($result->note());
            $path = $ctx->site->healthPath();
            if ($result->ok) {
                $ctx->reporter->succeed(sprintf('GET %s → %d in %.1fs', $path, $result->status, $result->seconds));
            } else {
                $ctx->reporter->fail(sprintf('%s returned %s after go-live (%d attempts)', $result->url, $result->status > 0 ? (string) $result->status : 'no response', $result->attempts), $ctx->log?->path);
            }

            return $result;
        } finally {
            if ($markerPath !== null && is_file($markerPath)) {
                @unlink($markerPath);
            }
        }
    }

    private function livePhp(DeployContext $ctx): string
    {
        return $this->php->forRelease($ctx->live?->phpBinary(), $ctx->sitePhp(), $ctx->reporter);
    }

    /**
     * @param array<string, mixed> $track
     */
    private function bringLiveUp(DeployContext $ctx, array $track): void
    {
        if ($track['maintenance'] && $ctx->live !== null) {
            $this->up($ctx, $ctx->live->dir, $this->livePhp($ctx));
        }
    }

    private function up(DeployContext $ctx, string $releaseDir, string $php): void
    {
        $result = $this->maintenance->up($releaseDir, $php, $ctx->options($ctx->timeout('artisan'), 'artisan up', cwd: $releaseDir));
        if (!$result->successful()) {
            $ctx->warn("php artisan up failed in {$releaseDir}: run it by hand (cpdeploy up {$ctx->name()})");
        }
    }

    /**
     * @param array<string, mixed> $track
     */
    private function revertPhp(DeployContext $ctx, array $track): void
    {
        if (!$track['multiphp'] || $ctx->domainPhpTag === null) {
            return;
        }
        try {
            $this->multiPhp->setVhostVersion($ctx->site->domain(), $ctx->domainPhpTag, 6);
            $ctx->state?->update(['multiphp_after' => null]);
        } catch (Throwable $e) {
            $ctx->warn(sprintf("Couldn't set the domain's PHP back to %s: %s — set it in cPanel → MultiPHP Manager", $ctx->domainPhpTag, $e->getMessage()));
        }
    }

    /**
     * Puts `current` back where it was before G5 (INV-09).
     */
    private function undoSwitch(DeployContext $ctx): void
    {
        $current = $ctx->paths->current($ctx->name());
        try {
            if ($ctx->live === null) {
                if (is_link($current)) {
                    @unlink($current);
                }
            } else {
                $this->fs->swapSymlink($current, 'releases/' . $ctx->live->id);
                $this->releases->markLive($ctx->live, null);
            }
            $ctx->state?->update(['switched' => false]);
        } catch (Throwable $e) {
            $ctx->warn("Couldn't put `current` back: " . $e->getMessage());
        }
    }

    private function markFailed(DeployContext $ctx): void
    {
        $release = $ctx->release;
        if ($release === null) {
            return;
        }
        $release->set('status', Release::FAILED);
        try {
            $release->save($this->fs);
        } catch (Throwable) {
            // Best effort: the release is removed by the next cleanup anyway.
        }
    }

    /**
     * GL-01: while migrations run, the first Ctrl+C only explains why it is ignored.
     */
    private function criticalOptions(DeployContext $ctx, float $timeout, string $label): RunOptions
    {
        $warned = false;

        return $ctx->options($timeout, $label)->reporting(
            fn (string $line) => $ctx->reporter->line($line),
            function () use ($ctx, &$warned): void {
                $ctx->reporter->tick();
                if (!$warned && $this->signals->pendingInCritical()) {
                    $warned = true;
                    $ctx->reporter->warn('Migrations are running — stopping now could leave the database half-migrated. Press Ctrl+C again within 3 s to force stop.');
                }
            },
        );
    }
}
