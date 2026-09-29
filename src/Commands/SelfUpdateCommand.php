<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Services;
use Cpdeploy\Ui\ErrorView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy self-update [--check] [--rollback] [--pre]` (§15.5).
 */
#[AsCommand(name: 'self-update', description: 'Update cpdeploy to the newest release (or --check, or --rollback)')]
final class SelfUpdateCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only say whether a newer version exists')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Go back to the version before the last update')
            ->addOption('pre', null, InputOption::VALUE_NONE, 'Include pre-releases (release candidates)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $updater = $this->services->selfUpdate();
        $theme = $this->services->theme();
        if ($input->getOption('rollback') === true) {
            $version = $updater->rollback();
            $output->writeln(sprintf('<fg=green>%s</> Rolled back%s. Run it again to return to the newer version.', $theme->symbol('ok'), $version !== '' ? ' to ' . ErrorView::escape($version) : ''));

            return 0;
        }

        $latest = $updater->latest($input->getOption('pre') === true);
        if ($latest === null) {
            $output->writeln("No releases of cpdeploy were found in {$updater->repo()}.");

            return 0;
        }
        if (!$updater->isNewer($latest)) {
            $output->writeln("cpdeploy {$updater->current()} is up to date (newest: {$latest->version}).");

            return 0;
        }
        if ($input->getOption('check') === true) {
            $output->writeln("cpdeploy {$latest->version} is available (you have {$updater->current()}). Update with: cpdeploy self-update");

            return 0;
        }
        $updater->update($latest, $this->services->reporter($input, $output));
        $output->writeln(sprintf('<fg=green>%s</> Updated cpdeploy %s → %s', $theme->symbol('ok'), $updater->current(), $latest->version));
        if (trim($latest->notes) !== '') {
            $output->writeln('');
            $output->writeln(ErrorView::escape(trim($latest->notes)));
        }

        return 0;
    }
}
