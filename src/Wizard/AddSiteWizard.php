<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Deploy\DeployResult;
use Cpdeploy\Menus\DeployScreen;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Wizard\Steps\AccessStep;
use Cpdeploy\Wizard\Steps\DeployStepsStep;
use Cpdeploy\Wizard\Steps\DomainStep;
use Cpdeploy\Wizard\Steps\EnvironmentStep;
use Cpdeploy\Wizard\Steps\NodeStep;
use Cpdeploy\Wizard\Steps\PhpStep;
use Cpdeploy\Wizard\Steps\ProjectTypeStep;
use Cpdeploy\Wizard\Steps\RepositoryStep;
use Cpdeploy\Wizard\Steps\ReviewStep;
use Cpdeploy\Wizard\Steps\ServingStep;

/**
 * The add-site wizard (§9.3): a step machine with Next / Back / Cancel. Nothing
 * is written before Review (WIZ-01); a cancel undoes the side effects (WIZ-03);
 * Create is a transaction (WIZ-04), optionally followed by the first deploy
 * (WIZ-05).
 */
final class AddSiteWizard
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    /**
     * Returns the name of the site created, or null when cancelled.
     */
    public function run(): ?string
    {
        $run = new WizardRun($this->ctx, new WizardState(), new WizardTransaction());
        /** @var array<int, WizardStep> $steps */
        $steps = [
            1 => new RepositoryStep(),
            2 => new AccessStep(),
            3 => new ProjectTypeStep(),
            4 => new DomainStep(),
            5 => new ServingStep(),
            6 => new PhpStep(),
            7 => new NodeStep(),
            8 => new EnvironmentStep(),
            9 => new DeployStepsStep(),
            10 => new ReviewStep(),
        ];
        $step = 1;
        while (true) {
            try {
                $next = $steps[$step]->run($run);
                $run->forward = $next !== WizardStep::BACK;
            } catch (CpdeployException $e) {
                if ($e->errorCode !== ErrorCode::CANCELLED) {
                    throw $e;
                }
                $next = WizardStep::CANCEL;
            }
            if ($next === WizardStep::NEXT) {
                $step = min(WizardRun::STEPS, $step + 1);
            } elseif ($next === WizardStep::BACK) {
                $step = max(1, $step - 1);
            } elseif (str_starts_with($next, WizardStep::GOTO)) {
                $step = max(1, min(WizardRun::STEPS, (int) substr($next, strlen(WizardStep::GOTO))));
            } elseif ($next === WizardStep::CANCEL) {
                if ($this->cancel($run)) {
                    return null;
                }
            } elseif ($next === WizardStep::CREATE || $next === WizardStep::CREATE_DEPLOY) {
                return $this->create($run, $next === WizardStep::CREATE_DEPLOY) ? $run->state->name : null;
            }
        }
    }

    /**
     * WIZ-03. Returns false when the user wants to stay.
     */
    private function cancel(WizardRun $run): bool
    {
        if (!$this->ctx->asker->confirm('Cancel adding this site?', false)) {
            return false;
        }
        $removeKey = $run->tx->hasKey()
            && $this->ctx->asker->confirm('Remove the deploy key created for this site? (also deletes it from GitHub)', true);
        $instruction = $this->ctx->services->repoAccess()->discard($run->tx, $run->state->repo, $run->state->name, $removeKey);
        if ($instruction !== null) {
            $this->ctx->warn($instruction);
        }
        $this->ctx->line('Nothing was added.');

        return true;
    }

    /**
     * WIZ-04, then WIZ-05 (and LEG-04 after an imported site's first deploy).
     */
    private function create(WizardRun $run, bool $deploy): bool
    {
        $state = $run->state;
        $access = $this->ctx->services->repoAccess();
        try {
            $this->ctx->title('Add a site', 'Create ' . $state->name);
            $this->ctx->services->siteCreator()->create($state, $run->tx, $this->ctx->reporter);
        } catch (CpdeployException $e) {
            $this->ctx->error($e);
            $removeKey = $run->tx->hasKey()
                && $this->ctx->asker->confirm('Remove the deploy key created for this site? (also deletes it from GitHub)', false);
            $instruction = $access->discard($run->tx, $state->repo, $state->name, $removeKey);
            if ($instruction !== null) {
                $this->ctx->warn($instruction);
            }
            $this->ctx->pause();

            return false;
        }
        $access->discard($run->tx, $state->repo, $state->name, false);
        $this->ctx->ok("Site {$state->name} created");
        if ($state->envMode === WizardState::ENV_LATER) {
            $this->ctx->warn("Deploys are blocked until .env exists: Manage site → Environment, or cpdeploy env {$state->name} edit");
        }
        if (!$deploy) {
            $this->ctx->pause();

            return true;
        }
        $result = (new DeployScreen($this->ctx))->deploy($state->name);
        if ($state->legacy !== null && $result !== null && in_array($result->result, [DeployResult::SUCCESS, DeployResult::WARNING], true)) {
            $this->legacyCleanup($state->legacy);
        }

        return true;
    }

    /**
     * LEG-04: the old cron line and webhook file; ~/deployments/<name> stays.
     */
    private function legacyCleanup(LegacySite $legacy): void
    {
        $importer = $this->ctx->services->legacyImporter();
        $this->ctx->title('Add a site', 'The old setup of ' . $legacy->name);
        $cron = $importer->cronLines($legacy->name);
        if ($cron !== []) {
            $this->ctx->line('The old script still runs from cron:');
            foreach ($cron as $line) {
                $this->ctx->line('  ' . $line);
            }
            if ($this->ctx->asker->confirm('Remove this cron line? cpdeploy deploys only when you ask it to', true)) {
                $importer->removeCronLines($legacy->name);
                $this->ctx->ok('Cron line removed');
            }
        }
        $hooks = $importer->webhookFiles($legacy);
        if ($hooks !== [] && $this->ctx->asker->confirm('Remove the old webhook file' . (count($hooks) === 1 ? '' : 's') . ' (' . implode(', ', array_map('basename', $hooks)) . ')?', true)) {
            $importer->removeWebhooks($hooks);
            $this->ctx->ok('Webhook file removed');
        }
        $this->ctx->line('Also delete the webhook on GitHub (repository → Settings → Webhooks): cpdeploy only deploys when you ask it to.');
        $this->ctx->line("{$legacy->dir} was left in place; remove it once you're happy with the new setup.");
        $this->ctx->pause();
    }
}
