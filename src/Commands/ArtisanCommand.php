<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Laravel\LaravelTools;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Asker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy artisan <site> -- <args…>` (§10.2): runs in the live release with
 * its PHP (PHP-07). Dangerous commands (LAR-08) need the site name typed, or
 * --yes without a terminal.
 */
#[AsCommand(name: 'artisan', description: 'Run php artisan in a site\'s live release')]
final class ArtisanCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::REQUIRED, 'Site name')
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The artisan command and its arguments (put them after --)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        /** @var list<string> $args */
        $args = array_values(array_filter((array) $input->getArgument('args'), 'is_string'));
        $asker = $this->services->asker($input);
        self::confirmDangerous($site, $args, $asker, $input->getOption('yes') === true);

        return $this->services->laravelTools()->artisan(
            $site,
            $args,
            $asker->interactive(),
            static fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW),
        );
    }

    /**
     * LAR-08 / INV-03: type the site name (terminal) or pass --yes (no terminal).
     *
     * @param list<string> $args
     */
    public static function confirmDangerous(string $site, array $args, Asker $asker, bool $yes): void
    {
        if (!LaravelTools::isDangerous($args)) {
            return;
        }
        if ($asker->interactive() && !$yes) {
            $typed = $asker->text(
                sprintf('php artisan %s can destroy data. Type the site name (%s) to run it', implode(' ', $args), $site),
                required: true,
            );
            if (trim($typed) !== $site) {
                throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — the name did not match; nothing was run', '');
            }

            return;
        }
        if (!$yes) {
            throw new CpdeployException(ErrorCode::NEEDS_ANSWER, sprintf('php artisan %s can destroy data: this needs --yes', $args[0] ?? ''), 'Run again with --yes if you are sure.');
        }
    }
}
