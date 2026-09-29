<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 3 (§9.3): the project type, detected at the branch head (PRJ-01).
 * Submodules or LFS there stop the wizard (GIT-14): Back or Cancel only.
 */
final class ProjectTypeStep implements WizardStep
{
    public const LABELS = [
        'laravel' => 'Laravel',
        'static' => 'Static site / SPA (Vite, React, Vue…)',
        'php' => 'Plain PHP',
        'custom' => "Custom (I'll define the steps)",
    ];

    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        if ($state->repo === null || $state->mirror === null || $state->branch === null) {
            return self::BACK;
        }
        $w->title(3, 'Project type');
        if ($state->files === null) {
            try {
                $state->files = $w->services()->repoAccess()->files($state->mirror, $state->repo, $state->branch);
            } catch (CpdeployException $e) {
                if (!in_array($e->errorCode, [ErrorCode::SUBMODULES, ErrorCode::LFS], true)) {
                    throw $e;
                }
                $ctx->error($e);
                $choice = $ctx->asker->select('What now?', [
                    'back' => $ctx->theme->symbol('back') . ' Back (pick another branch)',
                    'cancel' => 'Cancel',
                ], 'back');

                return $choice === 'cancel' ? self::CANCEL : self::BACK;
            }
        }
        $state->info ??= $w->services()->siteInspector()->detect($state->files);
        $ctx->line('Detected: ' . self::describe($state->info));

        $choice = (string) $ctx->choose('Project type', self::LABELS, $state->type ?? $state->info->detectedType);
        if ($choice === MenuContext::BACK) {
            return self::BACK;
        }
        if ($state->type !== null && $state->type !== $choice) {
            // WIZ-02: the served folder and the deploy steps depend on the type.
            $ctx->line('Changed project type — the served folder and deploy steps will be asked again');
            $state->webDir = null;
            $state->overrides = [];
        }
        $state->type = $choice;
        $state->webDir ??= $w->services()->siteInspector()->defaultWebDir($choice, $state->files);

        return self::NEXT;
    }

    public static function describe(ProjectInfo $info): string
    {
        return match ($info->detectedType) {
            'laravel' => 'Laravel' . ($info->laravelVersion !== null ? ' ' . self::short($info->laravelVersion) . ' (laravel/framework in composer.lock)' : ' (laravel/framework in composer.json)'),
            'static' => 'Static site / SPA (package.json has a "build" script)',
            'php' => 'Plain PHP (.php files)',
            default => 'nothing specific — choose the type',
        };
    }

    /**
     * "12.31.0" → "12.31".
     */
    private static function short(string $version): string
    {
        $version = ltrim($version, 'v');
        $parts = explode('.', $version);

        return count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : $version;
    }
}
