<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy node <site> [version|auto|none]` (§10.2, §9.5.5).
 */
#[AsCommand(name: 'node', description: 'Show or change the Node.js version a site builds with')]
final class NodeCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('version', InputArgument::OPTIONAL, 'auto (from the repo), none (no frontend build), or a version such as 20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $version = $input->getArgument('version');
        if (!is_string($version) || $version === '') {
            $config = $this->services->sites()->load($site);
            $live = $this->services->releases()->live($site);
            $output->writeln('Setting:      ' . $config->nodeVersion());
            $output->writeln('Live release: ' . ($live !== null && is_string($live->get('node.version')) ? $live->get('node.version') : '—'));

            return 0;
        }
        $config = $this->services->siteSettings()->setNode($site, $version);
        $output->writeln(sprintf('<fg=green>%s</> node.version is %s. Used from the next deploy: cpdeploy deploy %s', $this->services->theme()->symbol('ok'), $config->nodeVersion(), $site));

        return 0;
    }
}
