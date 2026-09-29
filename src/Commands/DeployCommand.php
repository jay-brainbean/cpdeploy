<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Deploy\DeployResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy deploy <site>` (§10.2, §11).
 */
#[AsCommand(name: 'deploy', description: 'Deploy a site: build a new release and switch to it')]
final class DeployCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Branch, tag or commit to deploy (default: the site\'s branch)')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Run composer install: auto, yes or no', 'auto')
            ->addOption('migrate', null, InputOption::VALUE_REQUIRED, 'Run migrations: auto, yes or no', 'auto')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Run db:seed: auto, yes or no', 'auto')
            ->addOption('skip-build', null, InputOption::VALUE_NONE, 'Reuse the live release\'s frontend build')
            ->addOption('skip-optimize', null, InputOption::VALUE_NONE, 'Don\'t run php artisan optimize')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Redeploy even if the commit is already live')
            ->addOption('allow-rewind', null, InputOption::VALUE_NONE, 'Deploy even if the branch history was rewritten')
            ->addOption('no-health-check', null, InputOption::VALUE_NONE, 'Skip the health check after go-live')
            ->addOption('on-health-fail', null, InputOption::VALUE_REQUIRED, 'When the health check fails: ask, rollback or keep')
            ->addOption('recover', null, InputOption::VALUE_NONE, 'Recover an interrupted operation first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $str = static fn (mixed $v): string => is_string($v) ? strtolower($v) : 'auto';
        $flags = new DeployFlags(
            ref: is_string($input->getOption('ref')) && $input->getOption('ref') !== '' ? $input->getOption('ref') : null,
            composer: $str($input->getOption('composer')),
            migrate: $str($input->getOption('migrate')),
            seed: $str($input->getOption('seed')),
            skipBuild: $input->getOption('skip-build') === true,
            skipOptimize: $input->getOption('skip-optimize') === true,
            force: $input->getOption('force') === true,
            allowRewind: $input->getOption('allow-rewind') === true,
            noHealthCheck: $input->getOption('no-health-check') === true,
            onHealthFail: is_string($input->getOption('on-health-fail')) ? $input->getOption('on-health-fail') : null,
            recover: $input->getOption('recover') === true,
            yes: $input->getOption('yes') === true,
        );

        $reporter = $this->services->reporter($input, $output);
        $result = $this->services->deployer()->deploy($site, $flags, $this->services->asker($input), $reporter);

        $theme = $this->services->theme();
        if ($result->result === DeployResult::NOTHING) {
            return 0;
        }
        if ($result->warnings !== []) {
            $output->writeln('');
            foreach (array_values(array_unique($result->warnings)) as $warning) {
                $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $warning));
            }
        }
        if ($result->message !== '') {
            $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $result->message));
        }
        if ($result->logPath !== null && $output->isVerbose()) {
            $output->writeln('Log: ' . $result->logPath);
        }

        return $result->exitCode;
    }
}
