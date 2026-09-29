<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Services;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Base for commands that act on one site (CLI-03): the site argument accepts the
 * site name; without it a TTY shows the site picker, otherwise exit 2.
 */
abstract class SiteCommand extends Command
{
    public function __construct(protected readonly Services $services)
    {
        parent::__construct();
    }

    protected function site(InputInterface $input, string $argument = 'site'): string
    {
        $name = $input->getArgument($argument);
        if (is_string($name) && $name !== '') {
            return $name;
        }
        $names = $this->services->sites()->names();
        if ($names === []) {
            throw new CpdeployException(ErrorCode::USAGE, 'No sites are set up yet', 'Add one first: cpdeploy add');
        }
        if (!$this->services->isInteractive($input)) {
            throw new CpdeployException(ErrorCode::USAGE, 'Which site? Pass the site name', 'Sites: ' . implode(', ', $names));
        }

        return (string) $this->services->asker($input)->select('Site', array_combine($names, $names));
    }
}
