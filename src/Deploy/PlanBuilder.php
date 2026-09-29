<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\CustomCommand;
use Cpdeploy\Laravel\MigrationCheck;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * Builds the DeployPlan from the change analysis, the site settings and the flags
 * (§11.3). All questions come first, in the PLN-01 order; with no TTY each one is
 * answered by its flag, else by its default with --yes, else the run stops with
 * every flag that would answer the open questions (NI-02).
 */
final class PlanBuilder
{
    /** @var list<string> flags that would answer the open questions (NI-02) */
    private array $missing = [];

    /**
     * @param MigrationCheck|null $livePending LAR-03 on the live release (best effort)
     */
    public function build(DeployContext $ctx, ?MigrationCheck $livePending = null): DeployPlan
    {
        $this->missing = [];
        $site = $ctx->site;
        $info = $ctx->info();
        $changes = $ctx->changes ?? throw new \LogicException('No change analysis');
        $flags = $ctx->flags;
        $laravel = $site->isLaravel();

        // 1. composer (PLN-02)
        [$composer, $composerReason] = $this->composer($ctx);

        // 2. frontend build (PLN-05)
        [$build, $buildReason] = $this->frontend($ctx);

        // 3. migrations (PLN-03)
        $migrate = DeployPlan::MIGRATE_NONE;
        $migrateReason = 'nothing new';
        $expected = [];
        if ($laravel && $site->step('migrate') !== 'off') {
            $pendingLive = $livePending !== null && $livePending->known ? $livePending->pending : [];
            $candidates = array_values(array_unique([...$changes->migrationsAdded, ...$pendingLive]));
            if ($flags->migrate !== DeployFlags::AUTO) {
                $migrate = $flags->migrate === DeployFlags::YES ? DeployPlan::MIGRATE_YES : DeployPlan::MIGRATE_NO;
                $migrateReason = '--migrate=' . $flags->migrate;
                $expected = $candidates;
            } elseif ($site->step('migrate') === 'every') {
                $migrate = DeployPlan::MIGRATE_AUTO;
                $migrateReason = 'every deploy, when pending';
            } elseif ($candidates !== []) {
                $expected = $candidates;
                $new = count($changes->migrationsAdded);
                $still = count(array_diff($pendingLive, $changes->migrationsAdded));
                $intro = $changes->firstDeploy()
                    ? sprintf('%d migration file%s (first deploy)', $new, $new === 1 ? '' : 's')
                    : trim(($new > 0 ? "{$new} new" : '') . ($still > 0 ? ($new > 0 ? ', ' : '') . "{$still} still pending from before" : ''));
                $names = array_slice($candidates, 0, 10);
                if (count($candidates) > 10) {
                    $names[] = 'and ' . (count($candidates) - 10) . ' more';
                }
                $answer = $this->ask($ctx, 'migrate', 'Migrations: ' . $intro, ['yes' => 'Yes, run them', 'no' => 'Skip'], 'yes', '--migrate=yes|no', $names);
                $migrate = $answer === 'yes' ? DeployPlan::MIGRATE_YES : DeployPlan::MIGRATE_NO;
                $migrateReason = $answer === 'yes' ? $intro : 'skipped';
            }
            if ($changes->migrationsAdded === [] && ($changes->migrationsModified !== [] || $changes->migrationsDeleted !== [])) {
                $ctx->warn("Changed migration files don't re-run; create a new migration instead");
            }
        }

        // 4. seed (PLN-04)
        $seed = false;
        $seedReason = '';
        if ($laravel) {
            $when = $site->step('seed');
            if ($flags->seed !== DeployFlags::AUTO) {
                $seed = $flags->seed === DeployFlags::YES;
                $seedReason = '--seed=' . $flags->seed;
            } elseif ($when === 'first' && $changes->firstDeploy()) {
                $seed = true;
                $seedReason = 'first deploy';
            } elseif ($when === 'ask') {
                $seed = $this->ask($ctx, 'seed', 'Seed the database (php artisan db:seed)?', ['yes' => 'Yes, seed', 'no' => 'No'], 'no', '--seed=yes|no') === 'yes';
                $seedReason = 'asked';
            }
        }

        // 5. custom commands with when: ask (both phases)
        $custom = [];
        foreach ($site->customCommands() as $command) {
            $custom[$command->index] = match ($command->when) {
                'every' => true,
                'first' => $changes->firstDeploy(),
                'ask' => $this->ask(
                    $ctx,
                    $command->key(),
                    sprintf('Run "%s"%s?', $command->name, $command->phase === CustomCommand::AFTER ? ' after go-live' : ''),
                    ['yes' => 'Run it', 'no' => 'Skip'],
                    'yes',
                    '--yes',
                ) === 'yes',
                default => false,
            };
        }

        if ($this->missing !== []) {
            throw new CpdeployException(
                ErrorCode::NEEDS_ANSWER,
                'This needs answers: ' . implode(' ', array_values(array_unique($this->missing))) . ' (or --yes for the defaults)',
                'Pass the flags, or --yes to accept the defaults.',
            );
        }

        return new DeployPlan(
            $composer,
            $composerReason,
            $build,
            $buildReason,
            $migrate,
            $migrateReason,
            $seed,
            $seedReason,
            $custom,
            $laravel && $site->step('optimize') === 'every' && !$flags->skipOptimize,
            $laravel && $site->step('storage_link') === 'every',
            $site->healthEnabled() && !$flags->noHealthCheck,
            $expected,
        );
    }

    /**
     * PLN-10: one line per decision, then "Deploy <sha> to <domain>?". --yes skips it;
     * without a TTY it needs --yes (NI-03).
     */
    public function confirm(DeployContext $ctx): bool
    {
        $plan = $ctx->plan();
        $commit = $ctx->commit ?? throw new \LogicException('No target commit');
        $ctx->reporter->info(sprintf('Target: %s "%s" → %s', $commit->short, $commit->subject, $ctx->site->domain()));
        foreach ($plan->notes() as $note) {
            $ctx->reporter->info('  ' . $note);
        }
        if ($ctx->flags->yes || ($ctx->flags->answers['confirm'] ?? false)) {
            return true;
        }
        if (!$ctx->asker->interactive()) {
            throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'This needs answers: --yes (to confirm the deploy)', 'Run again with --yes.');
        }

        return $ctx->asker->select(
            sprintf('Deploy %s to %s?', $commit->short, $ctx->site->domain()),
            ['deploy' => 'Deploy', 'cancel' => 'Cancel'],
            'deploy',
        ) === 'deploy';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function composer(DeployContext $ctx): array
    {
        $site = $ctx->site;
        $info = $ctx->info();
        $changes = $ctx->changes ?? throw new \LogicException('No change analysis');
        $flags = $ctx->flags;
        if (!$info->hasComposer()) {
            return [DeployPlan::COMPOSER_NONE, 'no composer.json'];
        }
        if ($site->step('composer_install') === 'off') {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                'steps.composer_install is off, but the repo has composer.json',
                "Set it to ask or every: cpdeploy config {$site->name()} set steps.composer_install ask",
            );
        }
        $live = $ctx->live;
        $forced = match (true) {
            $changes->firstDeploy() => 'first deploy',
            $live === null || !is_dir($live->dir . '/vendor') => 'no vendor to reuse',
            default => null,
        };
        if ($forced !== null) {
            if ($flags->composer === DeployFlags::NO) {
                throw new CpdeployException(ErrorCode::USAGE, "--composer=no isn't possible: {$forced}", 'Run without --composer=no; dependencies must be installed.');
            }

            return [DeployPlan::COMPOSER_INSTALL, $forced];
        }
        if ($site->step('composer_install') === 'every') {
            return [DeployPlan::COMPOSER_INSTALL, 'every deploy'];
        }
        if ($flags->composer !== DeployFlags::AUTO) {
            return [$flags->composer === DeployFlags::YES ? DeployPlan::COMPOSER_INSTALL : DeployPlan::COMPOSER_REUSE, '--composer=' . $flags->composer];
        }

        $options = ['install' => 'Yes, install', 'reuse' => 'Skip (reuse vendor/ from live)'];
        $livePhp = $live->phpMajorMinor();
        $sitePhp = $ctx->sitePhp()->majorMinor();
        if ($livePhp !== null && $livePhp !== $sitePhp) {
            $why = "PHP changed {$livePhp} → {$sitePhp}: dependencies must be reinstalled";
            $answer = $this->ask($ctx, 'composer', 'composer install — ' . $why, $options, 'install', '--composer=yes|no');
            if ($answer === 'reuse' && $ctx->asker->interactive() && !isset($ctx->flags->answers['composer'])
                && !$ctx->asker->confirm("Reuse vendor/ built for PHP {$livePhp}? It may not work with PHP {$sitePhp}", false)) {
                $answer = 'install';
            }

            return $answer === 'install' ? [DeployPlan::COMPOSER_INSTALL, "PHP changed {$livePhp} → {$sitePhp}"] : [DeployPlan::COMPOSER_REUSE, 'skipped although PHP changed'];
        }
        if ($changes->composerChanged) {
            $summary = ComposerInspector::summary($changes->lockDiff);
            $answer = $this->ask($ctx, 'composer', 'composer install — composer.lock changed: ' . $summary, $options, 'install', '--composer=yes|no');

            return $answer === 'install' ? [DeployPlan::COMPOSER_INSTALL, 'lock changed'] : [DeployPlan::COMPOSER_REUSE, 'skipped although the lock changed'];
        }
        $answer = $this->ask($ctx, 'composer', 'composer install — composer.json and composer.lock are unchanged', $options, 'reuse', '--composer=yes|no');

        return $answer === 'install' ? [DeployPlan::COMPOSER_INSTALL, 'asked'] : [DeployPlan::COMPOSER_REUSE, 'no changes'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function frontend(DeployContext $ctx): array
    {
        $site = $ctx->site;
        $info = $ctx->info();
        if ($site->step('frontend_build') === 'off' || strtolower($site->nodeVersion()) === 'none') {
            return [DeployPlan::BUILD_NONE, 'off'];
        }
        if (!NodeInspector::builds($site, $info)) {
            return [DeployPlan::BUILD_NONE, sprintf('package.json has no "%s" script', $site->buildScript())];
        }
        $canReuse = $this->canReuseBuild($ctx);
        if ($ctx->flags->skipBuild) {
            return $canReuse ? [DeployPlan::BUILD_REUSE, '--skip-build'] : [DeployPlan::BUILD_RUN, 'nothing to reuse'];
        }
        if ($site->step('frontend_build') === 'every') {
            return [DeployPlan::BUILD_RUN, 'every deploy'];
        }
        if (!$canReuse) {
            return [DeployPlan::BUILD_RUN, $ctx->firstDeploy() ? 'first deploy' : 'nothing to reuse'];
        }
        $relevant = $ctx->changes?->frontendRelevant() ?? true;
        $answer = $this->ask(
            $ctx,
            'frontend',
            'Frontend build — ' . ($relevant ? 'files changed' : 'no frontend changes'),
            ['build' => 'Yes, build', 'reuse' => 'Skip (reuse the build from live)'],
            $relevant ? 'build' : 'reuse',
            '--skip-build',
        );

        return $answer === 'build' ? [DeployPlan::BUILD_RUN, 'asked'] : [DeployPlan::BUILD_REUSE, 'skipped'];
    }

    /**
     * NODE-13: every build output exists in the live release.
     */
    private function canReuseBuild(DeployContext $ctx): bool
    {
        if ($ctx->live === null) {
            return false;
        }
        $outputs = NodeInspector::buildOutputs($ctx->site, $ctx->info());
        foreach ($outputs as $path) {
            if (!file_exists($ctx->live->dir . '/' . $path)) {
                return false;
            }
        }

        return $outputs !== [];
    }

    /**
     * One question (records unanswered ones in $missing). A pre-set answer wins; with a TTY it is asked; without one,
     * --yes takes the default, otherwise the flag is recorded as missing (NI-02).
     *
     * @param array<string, string> $options
     * @param list<string> $details
     *
     * @phpstan-impure
     */
    private function ask(DeployContext $ctx, string $key, string $label, array $options, string $default, string $flag, array $details = []): string
    {
        $preset = $ctx->flags->answers[$key] ?? null;
        if ($preset !== null) {
            return $preset ? (string) array_key_first($options) : (string) array_key_last($options);
        }
        // CLI-02: --yes accepts every default answer, with or without a TTY.
        if ($ctx->flags->yes) {
            return $default;
        }
        if (!$ctx->asker->interactive()) {
            $this->missing[] = $flag;

            return $default;
        }
        if ($this->missing !== []) {
            return $default;
        }
        foreach ($details as $line) {
            $ctx->reporter->info('    ' . $line);
        }

        return (string) $ctx->asker->select($label, $options, $default);
    }
}
