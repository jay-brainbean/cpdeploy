<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\CustomCommand;
use Cpdeploy\Config\Paths;
use Cpdeploy\Deploy\Steps\ComposerStep;
use Cpdeploy\Deploy\Steps\CustomCommandStep;
use Cpdeploy\Deploy\Steps\DocrootFilesStep;
use Cpdeploy\Deploy\Steps\ExportStep;
use Cpdeploy\Deploy\Steps\FrontendBuildStep;
use Cpdeploy\Deploy\Steps\LinkSharedStep;
use Cpdeploy\Deploy\Steps\OptimizeStep;
use Cpdeploy\Deploy\Steps\StorageLinkStep;
use Cpdeploy\Laravel\MigrationCheck;
use Cpdeploy\Laravel\MigrationStatus;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * Phase B (§11.4): builds the new release. Only the new release folder changes;
 * the live site is never touched here.
 */
final class Builder
{
    public function __construct(
        private readonly StepRunner $runner,
        private readonly ExportStep $export,
        private readonly LinkSharedStep $shared,
        private readonly DocrootFilesStep $docroot,
        private readonly ComposerStep $composer,
        private readonly FrontendBuildStep $frontend,
        private readonly StorageLinkStep $storageLink,
        private readonly OptimizeStep $optimize,
        private readonly MigrationStatus $migrations,
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly ReleaseManifest $manifest,
    ) {
    }

    public function build(DeployContext $ctx): void
    {
        $this->runner->run($ctx, $this->export);            // B1
        $this->runner->run($ctx, $this->shared);            // B2
        $docrootLater = !DocrootFilesStep::ready($ctx);
        if (!$docrootLater) {
            $this->runner->run($ctx, $this->docroot);       // B3
        }
        $this->runner->run($ctx, $this->composer);          // B4
        $this->runner->run($ctx, $this->frontend);          // B5
        if ($docrootLater) {
            if (!DocrootFilesStep::ready($ctx)) {
                throw new CpdeployException(
                    ErrorCode::BUILD_OUTPUT,
                    sprintf("The web dir '%s' doesn't exist in the release", $ctx->site->webDir()),
                    'Check domain.web_dir (cpdeploy config ' . $ctx->name() . ' get domain.web_dir) and the build output folder.',
                );
            }
            $this->runner->run($ctx, $this->docroot);       // B3, once the build made the web dir
        }
        $this->runner->run($ctx, $this->storageLink);       // B6
        $this->runner->run($ctx, $this->optimize);          // B7
        foreach ($ctx->site->customCommands(CustomCommand::BEFORE) as $command) {
            $this->runner->run($ctx, new CustomCommandStep($this->shell, $command)); // B8
        }
        $ctx->pendingAfterBuild = $this->migrationCheck($ctx); // B9
        $this->ready($ctx);                                  // B10
    }

    /**
     * B9: pending migrations in the new release (never fatal; unknown on failure).
     */
    private function migrationCheck(DeployContext $ctx): ?MigrationCheck
    {
        if (!$ctx->site->isLaravel() || $ctx->site->step('migrate') === 'off') {
            return null;
        }
        $check = $this->migrations->pending(
            $ctx->releaseDir(),
            $ctx->sitePhp()->binary,
            $ctx->info()->laravelMajor(),
            new RunOptions(timeout: $ctx->timeout('artisan'), pathPrefix: $ctx->pathPrefix()),
        );
        if (!$check->known) {
            $ctx->log?->write('Pending migrations: unknown. migrate:status said: ' . trim($check->raw));
        } else {
            $ctx->log?->write('Pending migrations: ' . ($check->pending === [] ? 'none' : implode(', ', $check->pending)));
        }

        return $check;
    }

    /**
     * B10: the manifest (DOC-06), the release marker for the health check (HC-01;
     * a random name, SEC-09), status ready, durations.
     */
    private function ready(DeployContext $ctx): void
    {
        $release = $ctx->release;
        if ($release === null) {
            return;
        }
        $this->manifest->write($release->dir);
        $web = $release->webPath($ctx->site->webDir());
        if (is_dir($web)) {
            $marker = '.cpd-release-' . bin2hex(random_bytes(16)) . '.txt';
            $this->fs->writeAtomic($web . '/' . $marker, $release->id, Paths::MODE_PUBLIC_FILE);
            $release->set('marker', $marker);
        }
        $release->set('status', Release::READY);
        $release->set('durations', $ctx->durations);
        $release->save($this->fs);
    }
}
