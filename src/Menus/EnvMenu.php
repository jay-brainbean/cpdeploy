<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Env\EnvFile;
use Cpdeploy\Env\EnvManager;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\LineDiff;
use Cpdeploy\Support\Masker;
use Symfony\Component\Console\Helper\Table;

/**
 * Environment (.env) (§9.5.7). Every change goes through EnvManager (backup,
 * ENV-06) and is followed by the ENV-08 question — as `cpdeploy env` does.
 */
final class EnvMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        $env = $this->ctx->services->envManager();
        while (true) {
            $this->ctx->title($site, 'Environment (.env)');
            if (!$env->exists($site)) {
                $this->ctx->line('.env does not exist yet: add a variable or open it in the editor to create it.');
            }
            $choice = $this->ctx->choose('Environment', [
                'view' => 'View',
                'reveal' => 'Reveal values…',
                'edit' => 'Edit a variable…',
                'add' => 'Add a variable…',
                'remove' => 'Remove a variable…',
                'editor' => 'Open in editor',
                'restore' => 'Restore a backup…',
                'apply' => 'Apply to live site',
            ]);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(fn () => $this->action($site, (string) $choice));
        }
    }

    private function action(string $site, string $choice): void
    {
        $env = $this->ctx->services->envManager();
        switch ($choice) {
            case 'view':
            case 'reveal':
                if ($choice === 'reveal' && !$this->ctx->asker->confirm('Show secret values (passwords, keys, tokens) on screen?', false)) {
                    return;
                }
                $table = new Table($this->ctx->output);
                $table->setHeaders(['Key', 'Value']);
                foreach ($env->listing($site, $choice === 'reveal') as [$key, $value]) {
                    $table->addRow([$key, $value === EnvManager::MASK ? $this->ctx->theme->symbol('mask') : str_replace("\n", '\n', $value)]);
                }
                $table->render();
                $this->ctx->pause();

                return;

            case 'edit':
                $key = $this->pickKey($site, 'Edit which variable?');
                if ($key === null) {
                    return;
                }
                $current = $env->read($site)->get($key) ?? '';
                $value = $this->ctx->asker->text("New value of {$key}", $this->isSecret($key) ? '' : $current, $this->isSecret($key) ? '(hidden; leave empty to keep it)' : '');
                if ($value === '' && $this->isSecret($key)) {
                    return;
                }
                $env->set($site, $key, $value);
                $this->ctx->ok("{$key} saved (the previous .env is in env-backups)");
                break;

            case 'add':
                $key = $this->ctx->asker->text('Name', placeholder: 'MAIL_HOST', required: true, validate: static fn (string $k): ?string => preg_match(EnvFile::KEY_PATTERN, $k) === 1 ? null : 'Letters, digits, _ and ., not starting with a digit');
                $value = $this->isSecret($key) ? $this->ctx->asker->password("Value of {$key}") : $this->ctx->asker->text("Value of {$key}");
                $env->set($site, $key, $value);
                $this->ctx->ok("{$key} saved (the previous .env is in env-backups)");
                break;

            case 'remove':
                $key = $this->pickKey($site, 'Remove which variable?');
                if ($key === null || !$this->ctx->asker->confirm("Remove {$key}?", false)) {
                    return;
                }
                $env->unset($site, $key);
                $this->ctx->ok("{$key} removed");
                break;

            case 'editor':
                $current = $env->exists($site) ? $env->read($site)->toString() : '';
                $edited = $this->ctx->services->editor()->edit($current, 'env', static function (string $text): ?string {
                    try {
                        EnvFile::parse($text);

                        return null;
                    } catch (CpdeployException $e) {
                        return $e->getMessage();
                    }
                }, $this->ctx->asker, $this->ctx->reporter);
                if ($edited === null) {
                    $this->ctx->line('Nothing was changed.');

                    return;
                }
                $env->save($site, $edited, 'edited in the editor');
                $this->ctx->ok('.env saved (the previous one is in env-backups)');
                break;

            case 'restore':
                $backups = $env->backups($site);
                if ($backups === []) {
                    $this->ctx->line('No .env backups yet.');

                    return;
                }
                $name = $this->ctx->choose('Restore which backup? (newest first)', array_combine($backups, $backups));
                if ($name === MenuContext::BACK) {
                    return;
                }
                $current = $env->exists($site) ? $env->read($site)->toString() : '';
                $this->ctx->line('Changes (- now, + the backup):');
                foreach (LineDiff::lines($current, $env->backupContent($site, (string) $name)) ?? ['(too large to show)'] as $line) {
                    $this->ctx->line('  ' . $line);
                }
                if (!$this->ctx->asker->confirm("Restore {$name}?", false)) {
                    return;
                }
                $env->restore($site, (string) $name);
                $this->ctx->ok("{$name} restored");
                break;

            case 'apply':
                $env->apply($site, $this->ctx->reporter);

                return;
        }

        // ENV-08 after every change.
        $config = $this->ctx->services->sites()->load($site);
        if ($config->isLaravel() && $this->ctx->services->releases()->liveId($site) !== null
            && $this->ctx->asker->confirm('Apply to the live site now? (runs php artisan optimize on the live release)', true)) {
            $env->apply($site, $this->ctx->reporter);
        }
    }

    private function pickKey(string $site, string $label): ?string
    {
        $keys = $this->ctx->services->envManager()->read($site)->keys();
        if ($keys === []) {
            $this->ctx->line('.env has no variables.');

            return null;
        }
        $choice = $this->ctx->pick($label, array_combine($keys, $keys));

        return $choice === MenuContext::BACK ? null : $choice;
    }

    private function isSecret(string $key): bool
    {
        return Masker::isSecretKey($key);
    }
}
