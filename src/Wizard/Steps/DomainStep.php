<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Support\Fs;
use Cpdeploy\Wizard\DomainRow;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardState;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 4 (§9.3): the domain and its folder (DOC-01), notices about what the
 * first deploy does to it, and the offer to import an existing Laravel app's
 * .env and storage/ (copied at Create).
 */
final class DomainStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $inspector = $w->services()->siteInspector();
        while (true) {
            $w->title(4, 'Domain');
            $rows = $inspector->domainRows($state);
            $options = [];
            $width = 0;
            foreach ($rows as $row) {
                $width = max($width, mb_strlen($row->domain->name), mb_strlen($w->tilde($row->docroot)));
            }
            $current = null;
            foreach ($rows as $i => $row) {
                $options['d:' . $i] = self::pad($row->domain->name, $width) . '  ' . self::pad($w->tilde($row->docroot), $width) . '  ' . $row->status;
                if ($row->domain->name === $state->domain && $row->docroot === $state->docroot) {
                    $current = 'd:' . $i;
                }
            }
            $options['other'] = 'Other folder…';
            $choice = (string) $ctx->choose('Deploy to which domain?', $options, $current ?? $this->firstAvailable($rows));
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
            if ($choice === 'other') {
                $row = $this->otherFolder($w, $rows);
                if ($row === null) {
                    continue;
                }
            } else {
                $row = $rows[(int) substr($choice, 2)];
            }
            if (!$row->available) {
                $ctx->warn((string) $row->problem);
                continue;
            }

            return $this->accept($w, $row);
        }
    }

    /**
     * Shows the notices for the chosen row, and asks about an existing app.
     */
    private function accept(WizardRun $w, DomainRow $row): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $inspector = $w->services()->siteInspector();
        $home = $w->services()->paths()->home();
        $siteChanged = $state->domain !== null && ($state->domain !== $row->domain->name || $state->docroot !== $row->docroot);
        if ($siteChanged) {
            $ctx->line('Changed domain — the .env and database answers will be asked again');
            $state->resetFromDomain();
        }

        if (preg_match('/^(\d+) items?$/', $row->status, $m) === 1) {
            $ctx->line(sprintf(
                'This folder has %s. At the first deploy they will be moved to %s (nothing is deleted).',
                $row->status,
                $w->tilde($w->services()->paths()->siteDir($state->name)) . '/backups/',
            ));
        }
        if ($row->domain->type === Domain::MAIN && Fs::normalize($row->docroot) === Fs::normalize($home . '/public_html')) {
            $ctx->line('This is the main domain: at the first deploy ~/public_html becomes a link to the live release.');
        }
        if ($row->docroot !== $row->domain->documentRoot) {
            $ctx->warn(sprintf(
                'cPanel serves %s from %s. Point the domain at %s in cPanel → Domains before the first deploy.',
                $row->domain->name,
                $w->tilde($row->domain->documentRoot),
                $w->tilde($row->docroot),
            ));
        }

        $app = $row->app;
        $importFrom = null;
        while ($app !== null && ($state->type ?? 'laravel') === 'laravel') {
            $ctx->line('Found an existing Laravel app at ' . $w->tilde($app) . ' (has .env' . (is_dir($app . '/storage') ? ' and storage/' : '') . ').');
            $choice = (string) $ctx->asker->select('Use it?', [
                'import' => 'Import its .env and storage/ (recommended)',
                'fresh' => 'Start fresh',
                'other' => 'Choose another folder…',
            ], 'import');
            if ($choice === 'import') {
                $importFrom = $app;
                break;
            }
            if ($choice === 'fresh') {
                break;
            }
            $path = $w->text('Folder of the app (with artisan and .env)', '', '~/shop', static function (string $v) use ($w): ?string {
                $dir = $w->expand($v);

                return is_file($dir . '/artisan') && is_file($dir . '/.env') ? null : 'No Laravel app (artisan and .env) in that folder';
            });
            if ($path !== null) {
                $app = $w->expand($path);
            }
        }

        $state->domain = $row->domain->name;
        $state->docroot = $row->docroot;
        $state->ip = $row->domain->ip;
        if ($importFrom !== null) {
            $state->importFrom = $importFrom;
            $state->envMode = WizardState::ENV_IMPORT;
        } elseif ($state->importFrom !== null) {
            $state->importFrom = null;
            $state->envMode = WizardState::ENV_EXAMPLE;
        }
        if ($state->appUrl === '') {
            $state->appUrl = 'https://' . $row->domain->name;
        }

        return self::NEXT;
    }

    /**
     * *Other folder…*: a domain, then a path validated with DOC-01.
     *
     * @param list<DomainRow> $rows
     */
    private function otherFolder(WizardRun $w, array $rows): ?DomainRow
    {
        $ctx = $w->ctx;
        $domains = [];
        foreach ($rows as $row) {
            $domains[$row->domain->name] = $row->domain;
        }
        if ($domains === []) {
            $ctx->warn('This account has no domains to deploy to.');

            return null;
        }
        $options = array_combine(array_keys($domains), array_keys($domains));
        $name = (string) $ctx->choose('Which domain will serve the folder?', $options, $w->state->domain ?? array_key_first($options));
        if ($name === MenuContext::BACK) {
            return null;
        }
        $path = $w->text('Folder (absolute path or ~/…)', '', '~/shop', static fn (string $v): ?string => str_starts_with($v, '/') || str_starts_with($v, '~/') ? null : 'An absolute path, or one starting with ~/');
        if ($path === null) {
            return null;
        }
        $all = $w->services()->domains()->all();

        return $w->services()->siteInspector()->row($w->state, $domains[$name], $w->expand($path), $all);
    }

    /**
     * @param list<DomainRow> $rows
     */
    private function firstAvailable(array $rows): string
    {
        foreach ($rows as $i => $row) {
            if ($row->available) {
                return 'd:' . $i;
            }
        }

        return 'other';
    }

    private static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
    }
}
