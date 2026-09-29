<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Deploy\RecoveryResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy recover <site>` (§10.2, §11.9).
 */
#[AsCommand(name: 'recover', description: 'Finish or undo a deploy or rollback that was interrupted')]
final class RecoverCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $result = $this->services->recovery()->recover(
            $site,
            $input->getOption('yes') === true,
            $this->services->asker($input),
            $this->services->reporter($input, $output),
        );
        if ($result->result === RecoveryResult::NOTHING) {
            return 0;
        }

        // REC-03: what the user should check.
        $theme = $this->services->theme();
        if ($result->checks !== []) {
            $output->writeln('');
            foreach ($result->checks as $check) {
                $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $check));
            }
        }
        if ($result->logPath !== null && $output->isVerbose()) {
            $output->writeln('Log: ' . $result->logPath);
        }

        return 0;
    }
}
