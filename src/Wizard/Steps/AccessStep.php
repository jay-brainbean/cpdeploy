<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Git\ManualKeyInstructions;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 2 (§9.3): port 22 or 443 (GIT-04), the deploy key (created, added with
 * the token, or shown for adding by hand — GIT-05, GIT-17), the access test
 * (GIT-06), the branch when step 1 couldn't list them (GIT-15), and the
 * temporary bare clone (WIZ-01).
 */
final class AccessStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $access = $w->services()->repoAccess();
        $repo = $state->repo;
        if ($repo === null) {
            return self::BACK;
        }
        $w->title(2, 'GitHub access');

        // Access was already checked and the repository downloaded: nothing to redo.
        if ($state->mirror !== null && $state->files !== null && $state->branch !== null) {
            $ctx->ok("Access OK · {$repo->fullName()} @ {$state->branch}");

            return $w->forward ? self::NEXT : self::BACK;
        }

        try {
            $state->transport = $access->detectTransport();
            $ctx->ok('Connected to GitHub (port ' . ($state->transport === RepoUrl::TRANSPORT_SSH443 ? '443' : '22') . ')');
            if ($state->transport === RepoUrl::TRANSPORT_SSH443) {
                $ctx->line("Port 22 is blocked here — using GitHub's port 443.");
            }
        } catch (CpdeployException) {
            // The access test below says what is wrong.
            $state->transport = RepoUrl::TRANSPORT_SSH22;
        }

        $manual = null;
        if ($state->legacy !== null && is_file($state->legacy->keyPath) && !is_file($access->keyPath($state->name))) {
            // LEG-03: the old key is already on GitHub; copied, never moved.
            $access->copyLegacyKey($state->legacy->keyPath, $state->name, $w->tx);
            $ctx->ok('Copied the deploy key of the old setup to ' . $w->tilde($access->keyPath($state->name)));
        } else {
            $existed = is_file($access->keyPath($state->name));
            $manual = $access->prepareKey($repo, $state->name, $state, $w->tx, $access->api());
            if (!$existed) {
                $ctx->ok('Created deploy key ' . $w->tilde($access->keyPath($state->name)));
            }
            if ($manual === null && $state->keyId !== null && $w->tx->keyId === $state->keyId) {
                $ctx->ok("Added read-only deploy key to {$repo->fullName()}");
            }
        }

        $branches = $manual === null ? $this->check($w, $repo) : null;
        while (true) {
            if ($branches !== null) {
                break;
            }
            if ($manual !== null) {
                foreach ($manual->lines($repo->fullName()) as $line) {
                    $ctx->line($line);
                }
            }
            $choice = $ctx->asker->select('Add the key, then check access', [
                'check' => "I've added it — check access",
                'show' => 'Show the key again',
                'back' => $ctx->theme->symbol('back') . ' Back',
                'cancel' => 'Cancel',
            ], 'check');
            if ($choice === 'back') {
                return self::BACK;
            }
            if ($choice === 'cancel') {
                return self::CANCEL;
            }
            $manual ??= $this->instructions($w, $repo);
            if ($choice === 'check') {
                $branches = $this->check($w, $repo);
            }
        }

        if ($state->branch === null) {
            if ($branches === []) {
                $ctx->warn('The repository has no branches yet: push a commit first.');

                return self::BACK;
            }
            $default = null;
            try {
                $default = $access->defaultBranch($repo, $state->name, $state->transport);
            } catch (CpdeployException) {
                $default = null;
            }
            $options = array_combine($branches, $branches);
            $choice = (string) $ctx->choose('Which branch?', $options, $default !== null && isset($options[$default]) ? $default : $branches[0]);
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
            $state->branch = $choice;
        } elseif ($branches !== [] && !in_array($state->branch, $branches, true)) {
            $ctx->warn("The branch {$state->branch} doesn't exist in {$repo->fullName()}: choose another one at step 1.");

            return self::BACK;
        }

        if ($state->mirror === null) {
            $reporter = $ctx->reporter;
            $reporter->start('Downloading repository…');
            try {
                $state->mirror = $access->cloneTemporary($repo, $state->name, $state->transport, $w->tx);
            } catch (CpdeployException $e) {
                $reporter->fail($e->getMessage());
                $ctx->error($e);

                return self::BACK;
            }
            $reporter->succeed($repo->fullName());
        }

        return self::NEXT;
    }

    /**
     * GIT-06: the branches, or null (with the reason shown) when access fails.
     *
     * @return list<string>|null
     */
    private function check(WizardRun $w, RepoUrl $repo): ?array
    {
        try {
            $branches = $w->services()->repoAccess()->test($repo, $w->state->name, $w->state->transport);
            $w->ctx->ok('Access OK');

            return $branches;
        } catch (CpdeployException $e) {
            if ($e->errorCode === ErrorCode::CANCELLED) {
                throw $e;
            }
            $w->ctx->error($e);

            return null;
        }
    }

    private function instructions(WizardRun $w, RepoUrl $repo): ManualKeyInstructions
    {
        $system = $w->services()->system();

        return $w->services()->deployKeys()->instructions($repo, $w->state->name, $system->userName(), $system->hostName());
    }
}
