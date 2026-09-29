<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Services;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\ErrorView;
use Cpdeploy\Wizard\AddSiteWizard;
use Cpdeploy\Wizard\WizardState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy add` (§9.3): the add-site wizard on a terminal, or
 * `add --from=<file.yml>` without questions.
 */
#[AsCommand(name: 'add', description: 'Add a site: the wizard, or --from=<file.yml> without questions')]
final class AddCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'A site.yml with a setup: section (env, app_url, database, db_existing, deploy_now)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getOption('from');
        if (is_string($from) && $from !== '') {
            return $this->fromFile($from, $input, $output);
        }
        $asker = $this->services->asker($input);
        if (!$asker->interactive()) {
            throw new CpdeployException(ErrorCode::USAGE, 'The add-site wizard needs a terminal', 'Without one, use: cpdeploy add --from=<file.yml>');
        }
        $context = new MenuContext($this->services, $asker, $output, $this->services->reporter($input, $output), $this->services->theme());
        (new AddSiteWizard($context))->run();

        return 0;
    }

    private function fromFile(string $file, InputInterface $input, OutputInterface $output): int
    {
        $theme = $this->services->theme();
        $masker = $this->services->masker();
        $reporter = $this->services->reporter($input, $output);
        $result = $this->services->addFromFile()->run($file, $reporter);
        if (!$result->isCreated()) {
            $output->writeln('');
            foreach ($result->instructions as $line) {
                $output->writeln(ErrorView::escape($masker->mask($line)));
            }
            $output->writeln('');
            $output->writeln('Then run the same command again: the key is reused and the site is created.');

            return 2;
        }
        $config = $result->config;
        $state = $result->state;
        if ($config === null || $state === null) {
            return 1;
        }
        $output->writeln(sprintf('<fg=green>%s</> Site %s created', $theme->symbol('ok'), ErrorView::escape($config->name())));
        if ($state->envMode === WizardState::ENV_LATER && $config->isLaravel()) {
            $output->writeln(sprintf('<fg=yellow>%s</> Deploys are blocked until .env exists: cpdeploy env %s edit', $theme->symbol('warn'), $config->name()));
        }
        if (!$result->deployNow) {
            $output->writeln("Deploy it with: cpdeploy deploy {$config->name()}");

            return 0;
        }
        // setup.deploy_now is the consent for the first deploy's questions (--yes).
        $deploy = $this->services->deployer()->deploy($config->name(), new DeployFlags(yes: true), $this->services->asker($input), $reporter);

        return DeployCommand::report($deploy, $output, $theme);
    }
}
