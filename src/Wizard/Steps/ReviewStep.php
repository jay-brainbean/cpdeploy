<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardState;
use Cpdeploy\Wizard\WizardStep;
use Throwable;

/**
 * Step 10 (§9.3): everything the site will be, then Create (WIZ-04), Create
 * and deploy (WIZ-05), Edit a step, or Cancel (WIZ-03).
 */
final class ReviewStep implements WizardStep
{
    public const STEP_NAMES = [
        1 => 'Repository',
        2 => 'GitHub access',
        3 => 'Project type',
        4 => 'Domain',
        5 => 'How the site is served',
        6 => 'PHP version',
        7 => 'Node.js',
        8 => 'Environment and database',
        9 => 'Deploy steps',
    ];

    private const SHORT = [
        'composer_install' => 'composer install',
        'frontend_build' => 'frontend build',
        'storage_link' => 'storage:link',
        'migrate' => 'migrate',
        'maintenance' => 'maintenance mode',
        'optimize' => 'optimize',
        'seed' => 'db:seed',
        'queue_restart' => 'queue:restart',
    ];

    public function run(WizardRun $w): string
    {
        $ctx = $w->ctx;
        $config = $w->state->config($w->preset());
        $w->title(10, 'Review');
        foreach (self::lines($w, $config) as [$label, $value]) {
            $ctx->line(sprintf('  %-11s %s', $label, $value));
        }
        $errors = SiteSchema::validate($config->toArray())['errors'];
        foreach ($errors as $error) {
            $ctx->warn($error);
        }

        $options = $errors === [] ? [
            self::CREATE_DEPLOY => 'Create site and deploy now',
            self::CREATE => 'Create site only',
        ] : [];
        $options['edit'] = 'Edit a step…';
        $options['back'] = $ctx->theme->symbol('back') . ' Back';
        $options['cancel'] = 'Cancel';
        $choice = (string) $ctx->asker->select('Create the site?', $options, (string) array_key_first($options));
        if ($choice === 'back') {
            return self::BACK;
        }
        if ($choice === 'edit') {
            $labels = [];
            foreach (self::STEP_NAMES as $n => $name) {
                $labels[(string) $n] = "{$n}. {$name}";
            }
            $step = (string) $ctx->choose('Which step?', $labels, '1');

            return ctype_digit($step) ? self::GOTO . $step : self::NEXT;
        }

        return $choice;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function lines(WizardRun $w, SiteConfig $config): array
    {
        $state = $w->state;
        $key = match (true) {
            $state->legacy !== null => 'the old setup\'s deploy key',
            $state->keyId !== null => 'deploy key added automatically',
            default => 'deploy key added by hand',
        };
        $web = $config->webDir() === '' ? 'the release root' : $config->webDir() . '/';
        $php = sprintf('PHP %s (%s%s)', $config->phpVersion(), $config->phpFamily(), $config->syncMultiPhp() ? ', set in MultiPHP at go-live' : '');
        $build = $config->buildScript() !== '' && ($state->info?->hasScript($config->buildScript()) ?? false);
        $node = $build
            ? sprintf('Node %s (%s)', $config->nodeVersion(), self::packageManager($state, $config))
            : 'Frontend build: none';
        $lines = [
            ['Site', $config->name()],
            ['Repo', "{$config->repo()->fullName()} @ {$config->branch()}  ({$key})"],
            ['Domain', sprintf('%s → releases (keep %d) · serves %s', $config->domain(), $config->keepReleases(), $web)],
            ['Folder', '~/' . $config->siteDir() . '  (current, releases, shared)'],
            ['Runtime', $php . ' · ' . $node . ($state->info?->hasComposer() ?? false ? ' · Composer ' . $config->composerVersion() : '')],
        ];
        if ($config->isLaravel()) {
            $lines[] = ['.env', self::env($w) . ' · ' . self::database($w)];
            if ($state->importFrom !== null) {
                $lines[] = ['Import', 'storage/ copied from ' . $w->tilde($state->importFrom)];
            }
        }
        $groups = [];
        foreach (self::SHORT as $step => $label) {
            if (!$config->isLaravel() && !in_array($step, ['composer_install', 'frontend_build'], true)) {
                continue;
            }
            if ($step === 'frontend_build' && !$build) {
                continue;
            }
            $groups[$config->step($step)][] = $label;
        }
        foreach ($config->customCommands() as $command) {
            $groups[$command->when][] = $command->name;
        }
        foreach (['every' => 'Every', 'ask' => 'Ask', 'first' => 'First only', 'with_migrations' => 'With migr.'] as $when => $label) {
            if (($groups[$when] ?? []) !== []) {
                $lines[] = [$label, implode(' · ', $groups[$when])];
            }
        }
        $lines[] = ['Health', $config->healthEnabled() ? sprintf('GET %s → %s', $config->healthPath(), $config->healthExpect()) : 'off'];

        return $lines;
    }

    private static function packageManager(WizardState $state, SiteConfig $config): string
    {
        if ($config->packageManager() !== 'auto' || $state->files === null) {
            return $config->packageManager();
        }
        try {
            return PackageManager::detect($state->info?->packageJson, $state->files->checker(), $state->files->reader())->name;
        } catch (CpdeployException) {
            return 'auto';
        }
    }

    private static function env(WizardRun $w): string
    {
        $state = $w->state;

        return match ($state->envMode) {
            WizardState::ENV_IMPORT => 'imported from ' . $w->tilde((string) $state->importFrom),
            WizardState::ENV_PASTE => 'pasted',
            WizardState::ENV_FILE => 'from ' . $state->envContent,
            WizardState::ENV_LATER => 'added later (deploys are blocked until it exists)',
            default => $state->files?->exists('.env.example') ?? false ? 'from .env.example' : 'from a minimal Laravel template',
        };
    }

    private static function database(WizardRun $w): string
    {
        $state = $w->state;
        switch ($state->dbMode) {
            case WizardState::DB_CREATE:
                try {
                    [$database, $user] = $w->services()->databases()->names($state->name);

                    return "DB {$database} (new, user {$user})";
                } catch (Throwable) {
                    return 'DB: a new MySQL database';
                }
            case WizardState::DB_EXISTING:
                return "DB {$state->dbName} (existing)";
            case WizardState::DB_SQLITE:
                return 'DB: SQLite';
            default:
                return $state->envMode === WizardState::ENV_IMPORT ? 'DB as in the imported .env' : 'DB_* set by you';
        }
    }
}
