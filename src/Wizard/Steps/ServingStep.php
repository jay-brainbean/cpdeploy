<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 5 (§9.3): how releases work, and the folder inside each release the
 * domain serves (PRJ-02).
 */
final class ServingStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        if ($state->files === null || $state->type === null) {
            return self::BACK;
        }
        $w->title(5, 'How the site is served');
        $ctx->line('Each deploy is built in its own release folder, then the domain switches to it');
        $ctx->line('instantly. Failed builds never touch the live site; rollback takes a second.');
        $ctx->line();

        if ($state->type === 'laravel') {
            $state->webDir = 'public';
            $ctx->line('Served folder inside each release:  public        (Laravel)');
            $choice = $ctx->choose('Continue?', ['continue' => 'Continue']);

            return $choice === MenuContext::BACK ? self::BACK : self::NEXT;
        }

        $detected = $w->services()->siteInspector()->defaultWebDir($state->type, $state->files);
        $current = $state->webDir ?? $detected;
        $options = [];
        foreach (['dist', 'build', 'public', ''] as $dir) {
            $options['w:' . $dir] = ($dir === '' ? '(release root)' : $dir) . ($dir === $detected ? '   (detected)' : '');
        }
        if (!isset($options['w:' . $current])) {
            $options['w:' . $current] = $current;
        }
        $options['other'] = 'Other…';
        while (true) {
            $choice = (string) $ctx->choose('Served folder inside each release', $options, 'w:' . $current);
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
            if ($choice !== 'other') {
                $state->webDir = substr($choice, 2);

                return self::NEXT;
            }
            $dir = $w->text('Folder, relative to the repository root', '', 'site/public', static fn (string $v): ?string => self::validate($v));
            if ($dir !== null) {
                $state->webDir = trim($dir, '/');

                return self::NEXT;
            }
        }
    }

    public static function validate(string $dir): ?string
    {
        $dir = trim($dir, '/');
        if ($dir === '' || str_starts_with($dir, '~')) {
            return 'A folder inside the repository, such as dist or site/public';
        }
        foreach (explode('/', $dir) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return 'A folder inside the repository, such as dist or site/public';
            }
        }

        return null;
    }
}
