<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Services;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy down <site> [--retry=N] [--secret=S]` and `cpdeploy up <site>`
 * (§10.2): maintenance mode of the live release (LAR-05).
 */
final class MaintenanceCommand extends SiteCommand
{
    public function __construct(Services $services, private readonly bool $down)
    {
        parent::__construct($services);
    }

    protected function configure(): void
    {
        $this
            ->setName($this->down ? 'down' : 'up')
            ->setDescription($this->down ? 'Put a site\'s live release into maintenance mode' : 'Take a site\'s live release out of maintenance mode')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name');
        if ($this->down) {
            $this
                ->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Retry-After seconds sent to visitors')
                ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Bypass secret: https://<domain>/<secret> lets you in');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $tools = $this->services->laravelTools();
        $reporter = $this->services->reporter($input, $output);
        if (!$this->down) {
            $tools->up($site, $reporter);

            return 0;
        }
        $retry = $input->getOption('retry');
        $secret = $input->getOption('secret');
        $url = $tools->down(
            $site,
            is_string($retry) && ctype_digit($retry) ? (int) $retry : null,
            is_string($secret) && $secret !== '' ? $secret : null,
            $reporter,
        );
        if ($url !== null) {
            $output->writeln('Bypass: ' . $url);
        }

        return 0;
    }
}
