<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Runtime\PhpInstall;

/**
 * PHP version (§9.5.4): the three versions, *Check served version* (HTTP-04),
 * the installed versions with PHP-05 compatibility against the live commit,
 * then *Redeploy now?* and, if not, *Also switch the domain's PHP now?*.
 * The `php` command does the same through PhpChange (ARC-03).
 */
final class PhpMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        $php = $this->ctx->services->phpChange();
        while (true) {
            $this->ctx->title($site, 'PHP version');
            foreach ($php->overview($site) as $label => $value) {
                $this->ctx->line(sprintf('%-14s %s', $label . ':', $value));
            }
            $choice = $this->ctx->choose('PHP version', ['probe' => 'Check served version', 'change' => 'Change the PHP version…']);
            if ($choice === MenuContext::BACK) {
                return;
            }
            if ($choice === 'probe') {
                $served = $php->probe($site);
                $this->ctx->line('Served PHP: ' . ($served ?? 'unknown (the site did not return a version; it may be behind authentication or a firewall)'));
                $this->ctx->pause();
                continue;
            }
            $this->change($site);
        }
    }

    private function change(string $site): void
    {
        $php = $this->ctx->services->phpChange();
        $config = $this->ctx->services->sites()->load($site);
        $this->ctx->reporter->start('Checking which versions the live commit works with');
        $options = [];
        foreach ($php->installs() as $install) {
            $problems = $php->compatibility($site, $install);
            $current = $install->majorMinor() === $config->phpVersion() && $install->family === $config->phpFamily() ? '  (current)' : '';
            $options[$install->tag()] = sprintf(
                'PHP %s (%s)%s  %s',
                $install->majorMinor(),
                $install->family,
                $current,
                match (true) {
                    $problems === null => '',
                    $problems === [] => $this->ctx->theme->symbol('ok') . ' compatible',
                    default => $this->ctx->theme->symbol('warn') . ' ' . implode(', ', array_slice($problems, 0, 2)),
                },
            );
        }
        $this->ctx->reporter->succeed('');
        $choice = $this->ctx->choose('Use which PHP?', $options);
        if ($choice === MenuContext::BACK) {
            return;
        }
        $tag = PhpInstall::parseTag((string) $choice);
        if ($tag === null) {
            return;
        }
        $config = $php->set($site, $tag[1], $tag[0]);
        $this->ctx->ok(sprintf('%s now uses PHP %s (%s)', $site, $config->phpVersion(), $config->phpFamily()));
        if ($this->ctx->services->releases()->liveId($site) === null) {
            $this->ctx->line('It is used from the first deploy.');

            return;
        }
        $v = $config->phpVersion();
        $redeploy = $this->ctx->asker->select(
            "Redeploy now with PHP {$v}? (recommended: rebuilds vendor/ for {$v}; the domain switches to {$v} at go-live)",
            ['yes' => 'Yes, deploy now', 'no' => 'No, only save the setting'],
            'yes',
        );
        if ($redeploy === 'yes') {
            (new DeployScreen($this->ctx))->deploy($site, new DeployFlags(composer: DeployFlags::YES, force: true));

            return;
        }
        if ($config->syncMultiPhp() && $this->ctx->asker->confirm(
            'Also switch the domain\'s PHP right now? The live release was built with another PHP — switching without rebuilding can break the site.',
            false,
        )) {
            $served = $php->switchNow($site, $this->ctx->reporter);
            $this->ctx->line('Served PHP: ' . ($served ?? 'unknown'));
            $this->ctx->pause();
        }
    }
}
