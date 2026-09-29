<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Menus\MainMenu;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy` / `cpdeploy menu` (UIG-01): the main menu on a terminal; without
 * one, the command list (exit 0).
 */
#[AsCommand(name: 'menu', description: 'Open the menu (the default on a terminal)')]
final class MenuCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asker = $this->services->asker($input);
        if (!$asker->interactive()) {
            $list = $this->getApplication()?->find('list');

            return $list !== null ? $list->run(new ArrayInput([]), $output) : 0;
        }
        $context = new MenuContext($this->services, $asker, $output, $this->services->reporter($input, $output), $this->services->theme());
        (new MainMenu($context))->run($this->services->firstRun());

        return 0;
    }
}
