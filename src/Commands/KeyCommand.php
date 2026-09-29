<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Git\ManualKeyInstructions;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy key <site> show | test | rotate` (§10.2, §7.4).
 */
#[AsCommand(name: 'key', description: 'Show, test or rotate a site\'s deploy key')]
final class KeyCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('action', InputArgument::OPTIONAL, 'show, test or rotate', 'show');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $keys = $this->services->siteKeys();
        $theme = $this->services->theme();
        $action = (string) $input->getArgument('action');

        switch ($action) {
            case 'show':
                $info = $keys->info($site);
                if (!$info['exists']) {
                    throw new CpdeployException(ErrorCode::GIT_AUTH, "The deploy key {$info['path']} is missing", "Create a new one: cpdeploy key {$site} rotate");
                }
                $output->writeln((string) $info['public'], OutputInterface::OUTPUT_RAW);
                $output->writeln('Fingerprint: ' . ($info['fingerprint'] ?? 'unknown'));
                $output->writeln('On GitHub:   ' . ($info['id'] !== null ? "key id {$info['id']}" : 'added by hand') . ' · ' . $info['keysUrl']);

                return 0;

            case 'test':
                $count = $keys->test($site);
                $output->writeln(sprintf('<fg=green>%s</> The deploy key can read the repository (%d branch%s)', $theme->symbol('ok'), $count, $count === 1 ? '' : 'es'));

                return 0;

            case 'rotate':
                $asker = $this->services->asker($input);
                $result = $keys->rotate($site, static function (ManualKeyInstructions $i) use ($asker, $output, $site): bool {
                    foreach ($i->lines($site) as $line) {
                        $output->writeln($line, OutputInterface::OUTPUT_RAW);
                    }
                    if (!$asker->interactive()) {
                        throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'Adding the new key by hand needs a terminal (or set a GitHub token: cpdeploy token set)', 'The old deploy key is unchanged.');
                    }

                    return $asker->confirm('Added it on GitHub (read-only)? cpdeploy tests it next', true);
                }, $this->services->reporter($input, $output));
                $output->writeln(sprintf('<fg=green>%s</> New deploy key in place and tested', $theme->symbol('ok')));
                if ($result->manualDelete !== null) {
                    $output->writeln(sprintf('<fg=yellow>%s</> %s', $theme->symbol('warn'), $result->manualDelete));
                }

                return 0;

            default:
                throw new CpdeployException(ErrorCode::USAGE, "Unknown action: {$action}", "Use: cpdeploy key {$site} show | test | rotate");
        }
    }
}
