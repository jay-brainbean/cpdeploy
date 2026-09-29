<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy rollback <site> [release]` (§10.2, §11.8).
 */
#[AsCommand(name: 'rollback', description: 'Switch a site back to an earlier release')]
final class RollbackCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('release', InputArgument::OPTIONAL, 'Release id to roll back to (default: pick one, or --previous)')
            ->addOption('previous', null, InputOption::VALUE_NONE, 'Roll back to the release before the live one')
            ->addOption('no-health-check', null, InputOption::VALUE_NONE, 'Skip the health check after switching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $release = $input->getArgument('release');
        $result = $this->services->rollbackService()->rollback(
            $site,
            is_string($release) && $release !== '' ? $release : null,
            $input->getOption('previous') === true,
            $input->getOption('yes') === true,
            $input->getOption('no-health-check') === true,
            false,
            $this->services->asker($input),
            $this->services->reporter($input, $output),
        );

        $theme = $this->services->theme();
        if ($result->warnings !== []) {
            $output->writeln('');
            foreach (array_values(array_unique($result->warnings)) as $warning) {
                $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $warning));
            }
        }
        if ($result->message !== '') {
            $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $result->message));
        }

        return $result->exitCode;
    }
}
