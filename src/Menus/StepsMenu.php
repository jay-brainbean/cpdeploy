<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;

/**
 * Deploy steps (§9.5.6, the editor of wizard step 9): when each built-in step
 * runs, custom commands, how many releases to keep, and the health check.
 * Every change is validated and saved at once (SiteSettings).
 */
final class StepsMenu
{
    /** Labels of the built-in steps (§9.3 step 9). */
    private const STEPS = [
        'composer_install' => 'composer install',
        'frontend_build' => 'Frontend build',
        'storage_link' => 'php artisan storage:link',
        'migrate' => 'php artisan migrate --force',
        'maintenance' => 'Maintenance mode',
        'optimize' => 'php artisan optimize',
        'seed' => 'php artisan db:seed --force',
        'queue_restart' => 'php artisan queue:restart',
    ];

    /** One-line explanations of each "when" value. */
    private const WHEN = [
        'every' => 'every deploy',
        'ask' => 'ask each deploy',
        'off' => 'off',
        'first' => 'first deploy only',
        'with_migrations' => 'while migrations run',
    ];

    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        $settings = $this->ctx->services->siteSettings();
        while (true) {
            $config = $this->ctx->services->sites()->load($site);
            $this->ctx->title($site, 'Deploy steps');
            $options = [];
            foreach (self::STEPS as $step => $label) {
                if (!$config->isLaravel() && !in_array($step, ['composer_install', 'frontend_build'], true)) {
                    continue;
                }
                $options['step:' . $step] = self::row($label, self::WHEN[$config->step($step)] ?? $config->step($step));
            }
            foreach ($config->customCommands() as $command) {
                $options['custom:' . $command->index] = self::row($command->name, ($command->phase === 'after_activate' ? 'after go-live, ' : '') . (self::WHEN[$command->when] ?? $command->when));
            }
            $options['add'] = '+ Add a custom command…';
            $options['keep'] = 'Keep last ' . $config->keepReleases() . ' releases';
            $options['health'] = $config->healthEnabled()
                ? sprintf('Health check: GET %s → expect %s', $config->healthPath(), $config->healthExpect())
                : 'Health check: off';
            $options['done'] = 'Done';
            $choice = (string) $this->ctx->choose('Select a step to change when it runs', $options, null, false);
            if ($choice === 'done') {
                return;
            }
            $this->ctx->attempt(function () use ($site, $choice, $config, $settings): void {
                if (str_starts_with($choice, 'step:')) {
                    $step = substr($choice, 5);
                    $values = [];
                    foreach (SiteSchema::STEP_VALUES[$step] ?? [] as $value) {
                        $values[$value] = self::WHEN[$value];
                    }
                    $when = $this->ctx->choose(self::STEPS[$step] ?? $step, $values, $config->step($step));
                    if ($when !== MenuContext::BACK) {
                        $settings->change($site, ['steps.' . $step => (string) $when]);
                    }
                } elseif (str_starts_with($choice, 'custom:')) {
                    $this->custom($site, $config, (int) substr($choice, 7));
                } elseif ($choice === 'add') {
                    $this->add($site, $config);
                } elseif ($choice === 'keep') {
                    $keep = $this->ctx->asker->text('Keep how many releases? (2–30, counting the live one)', (string) $config->keepReleases(), required: true, validate: static fn (string $v): ?string => ctype_digit($v) && (int) $v >= 2 && (int) $v <= 30 ? null : 'A whole number from 2 to 30');
                    $settings->change($site, ['releases.keep' => (int) $keep]);
                } elseif ($choice === 'health') {
                    $enabled = $this->ctx->asker->confirm('Check the site after each go-live?', $config->healthEnabled());
                    $changes = ['health_check.enabled' => $enabled];
                    if ($enabled) {
                        $changes['health_check.path'] = $this->ctx->asker->text('Path to request', $config->healthPath(), '/ or /up', true, static fn (string $v): ?string => str_starts_with($v, '/') ? null : 'The path starts with /');
                    }
                    $settings->change($site, $changes);
                }
            });
        }
    }

    /**
     * Custom command: name, command, phase, when, on_error (§9.3 step 9).
     */
    private function add(string $site, SiteConfig $config): void
    {
        $name = $this->ctx->asker->text('Name (shown in the progress output)', required: true);
        $run = $this->ctx->asker->text('Command (bash -c, in the release folder)', placeholder: 'php artisan sitemap:generate', required: true);
        $phase = $this->ctx->asker->select('When in the deploy?', ['before_activate' => 'Before go-live (in the new release)', 'after_activate' => 'After go-live (in current)'], 'before_activate');
        $when = $this->ctx->asker->select('How often?', ['every' => 'every deploy', 'ask' => 'ask each deploy', 'first' => 'first deploy only', 'off' => 'off'], 'every');
        $onError = $this->ctx->asker->select('If it fails', ['fail' => 'Fail the deploy', 'warn' => 'Only warn'], 'fail');
        $commands = $this->raw($config);
        $commands[] = ['name' => $name, 'run' => $run, 'phase' => (string) $phase, 'when' => (string) $when, 'timeout' => 300, 'on_error' => (string) $onError];
        $this->ctx->services->siteSettings()->change($site, ['custom_commands' => $commands]);
    }

    private function custom(string $site, SiteConfig $config, int $index): void
    {
        $commands = $this->raw($config);
        if (!isset($commands[$index])) {
            return;
        }
        $choice = $this->ctx->choose((string) ($commands[$index]['name'] ?? 'Command'), [
            'every' => 'Run every deploy',
            'ask' => 'Ask each deploy',
            'first' => 'First deploy only',
            'off' => 'Off',
            'remove' => 'Remove this command',
        ]);
        if ($choice === MenuContext::BACK) {
            return;
        }
        if ($choice === 'remove') {
            array_splice($commands, $index, 1);
        } else {
            $commands[$index]['when'] = (string) $choice;
        }
        $this->ctx->services->siteSettings()->change($site, ['custom_commands' => $commands]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function raw(SiteConfig $config): array
    {
        $list = $config->get('custom_commands', []);
        $out = [];
        foreach (is_array($list) ? $list : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function row(string $label, string $value): string
    {
        return str_pad($label . ' ', 38, '.') . ' ' . $value;
    }
}
