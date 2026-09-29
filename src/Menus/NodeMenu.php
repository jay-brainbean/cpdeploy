<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Runtime\NodeVersion;

/**
 * Node version (§9.5.5): auto, an installed version, Other…, or None.
 */
final class NodeMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        $config = $this->ctx->services->sites()->load($site);
        $this->ctx->title($site, 'Node version');
        $this->ctx->line('Now: ' . $config->nodeVersion());
        $options = ['auto' => 'auto (from the repo: .nvmrc, .node-version or package.json engines)'];
        foreach ($this->ctx->services->nodeLocator()->installed() as $node) {
            /** @var NodeVersion $node */
            $options[$node->major()] ??= 'Node ' . $node->major() . ' (installed: ' . $node->version . ')';
        }
        $options['other'] = 'Other…';
        $options['none'] = 'None (no frontend build)';
        $choice = $this->ctx->choose('Build with which Node?', $options, $config->nodeVersion() === 'none' ? 'none' : 'auto');
        if ($choice === MenuContext::BACK) {
            return;
        }
        $version = (string) $choice;
        if ($version === 'other') {
            $version = $this->ctx->asker->text('Node version', placeholder: '20, 20.11.1, >=18 <21 or lts/*', required: true);
        }
        $this->ctx->services->siteSettings()->setNode($site, $version);
        $this->ctx->ok("node.version is {$version}. Used from the next deploy.");
        if ($this->ctx->choose('Deploy now?', ['deploy' => 'Deploy now', 'later' => 'Later'], 'later', false) === 'deploy') {
            (new DeployScreen($this->ctx))->deploy($site, new DeployFlags());
        }
    }
}
