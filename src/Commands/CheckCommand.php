<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Check\CheckGroup;
use Cpdeploy\Check\CheckResult;
use Cpdeploy\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy check [--json]` (§9.7). Exit 0 when nothing failed, otherwise 3.
 */
#[AsCommand(name: 'check', description: 'Check that this server and account can run cpdeploy')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $groups = $this->services->serverCheck()->run();
        $failed = array_filter($groups, static fn (CheckGroup $g): bool => $g->hasFailures()) !== [];

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'groups' => array_map(static fn (CheckGroup $g): array => $g->toArray(), $groups),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $failed ? 3 : 0;
        }

        $theme = $this->services->theme();
        $masker = $this->services->masker();
        $output->writeln('<options=bold>cpdeploy · Server check</>');
        $counts = [CheckResult::OK => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0];
        foreach ($groups as $group) {
            $output->writeln('');
            $output->writeln('<options=bold>' . $group->name . '</>');
            foreach ($group->checks as $check) {
                if (isset($counts[$check->status])) {
                    $counts[$check->status]++;
                }
                $output->writeln(sprintf('  %s %s', $theme->status($check->status), $this->escape($masker->mask($check->message))));
                if ($check->hint !== '' && $check->status !== CheckResult::OK) {
                    $output->writeln(sprintf('      <fg=gray>%s %s</>', $theme->symbol('arrow'), $this->escape($check->hint)));
                }
            }
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '%d ok, %d warnings, %d problems',
            $counts[CheckResult::OK],
            $counts[CheckResult::WARN],
            $counts[CheckResult::FAIL],
        ));

        return $failed ? 3 : 0;
    }

    private function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
