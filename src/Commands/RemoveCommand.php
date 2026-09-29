<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Deploy\RemoveOptions;
use Cpdeploy\Deploy\SiteRemover;
use Cpdeploy\Docroot\DocrootDetach;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Menus\RemoveSiteMenu;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy remove <site>` (§9.5.14, RM-01…03). On a terminal without flags,
 * the Remove site screen; otherwise the flags decide, and --yes confirms (NI-03).
 */
#[AsCommand(name: 'remove', description: 'Remove a site from cpdeploy (the domain keeps a plain folder, the old one, or an empty one)')]
final class RemoveCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addOption('detach', null, InputOption::VALUE_NONE, 'Keep the site running from a plain copy in ~/<site>-app')
            ->addOption('restore-backup', null, InputOption::VALUE_NONE, 'Put back the folder from before cpdeploy')
            ->addOption('empty', null, InputOption::VALUE_NONE, 'Leave an empty folder (the site goes offline)')
            ->addOption('keep-key', null, InputOption::VALUE_NONE, 'Keep the deploy key (on GitHub and in ~/.ssh)')
            ->addOption('delete-shared', null, InputOption::VALUE_NONE, 'Delete .env and uploads instead of moving them to ~/cpdeploy/removed/')
            ->addOption('drop-db', null, InputOption::VALUE_NONE, 'Drop the database and user cpdeploy created');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $asker = $this->services->asker($input);
        $docroot = array_keys(array_filter([
            DocrootDetach::DETACH => $input->getOption('detach') === true,
            DocrootDetach::RESTORE => $input->getOption('restore-backup') === true,
            DocrootDetach::EMPTY => $input->getOption('empty') === true,
        ]));
        if (count($docroot) > 1) {
            throw new CpdeployException(ErrorCode::USAGE, 'Choose one of --detach, --restore-backup and --empty', 'Nothing was removed.');
        }
        $yes = $input->getOption('yes') === true;
        $flags = $docroot !== [] || $yes || $input->getOption('keep-key') === true || $input->getOption('delete-shared') === true || $input->getOption('drop-db') === true;
        $reporter = $this->services->reporter($input, $output);
        $context = new MenuContext($this->services, $asker, $output, $reporter, $this->services->theme());

        if (!$flags && $asker->interactive()) {
            (new RemoveSiteMenu($context))->run($site);

            return 0;
        }

        $remover = $this->services->siteRemover();
        $config = $this->services->sites()->load($site);
        $actions = $remover->docrootActions($config);
        $action = $docroot[0] ?? null;
        if ($actions !== [] && ($action === null || !in_array($action, $actions, true))) {
            throw new CpdeployException(
                ErrorCode::USAGE,
                $action === null ? "What should happen to {$config->domain()}?" : '--' . SiteRemover::flag($action) . " isn't possible for {$site}",
                'Pass one of: ' . implode(', ', array_map(static fn (string $a): string => '--' . SiteRemover::flag($a), $actions)),
            );
        }
        if (!$yes) {
            if (!$asker->interactive()) {
                throw new CpdeployException(ErrorCode::NEEDS_ANSWER, "Removing {$site} needs confirmation", 'Add --yes');
            }
            if (!$asker->confirm("Remove {$site}? This can't be undone", false)) {
                throw new CpdeployException(ErrorCode::CANCELLED, 'Not removed', '');
            }
        }
        $result = $remover->remove($site, new RemoveOptions(
            $actions === [] ? null : $action,
            $input->getOption('keep-key') !== true,
            $input->getOption('delete-shared') === true,
            $input->getOption('drop-db') === true,
        ), $reporter);
        RemoveSiteMenu::summary($context, $site, $result);

        return 0;
    }

}
