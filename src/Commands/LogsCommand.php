<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Ui\Format;
use Cpdeploy\Ui\Theme;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy logs <site> [id] [--last] [--failed] [--tail=N] [--json]` (§10.2,
 * §9.5.12). No id: the history table. An id (log name or release id) or --last:
 * that log, in the pager on a terminal.
 */
#[AsCommand(name: 'logs', description: 'List a site\'s operations or show a log')]
final class LogsCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('id', InputArgument::OPTIONAL, 'A log file name, or a release id (its newest log)')
            ->addOption('last', null, InputOption::VALUE_NONE, 'Show the newest log')
            ->addOption('failed', null, InputOption::VALUE_NONE, 'Failures only')
            ->addOption('tail', null, InputOption::VALUE_REQUIRED, 'Only the last N lines of the log');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $history = $this->services->history();
        $failed = $input->getOption('failed') === true;
        $id = $input->getArgument('id');

        $file = null;
        if (is_string($id) && $id !== '') {
            $file = $history->find($site, $id);
        } elseif ($input->getOption('last') === true) {
            $file = $history->last($site, $failed);
            if ($file === null) {
                $output->writeln("{$site} has no logs yet.");

                return 0;
            }
        }
        if ($file !== null) {
            $text = (string) file_get_contents($file);
            $tail = $input->getOption('tail');
            if (is_string($tail) && ctype_digit($tail)) {
                $text = implode("\n", array_slice(explode("\n", rtrim($text, "\n")), -(int) $tail)) . "\n";
                $output->write($text, false, OutputInterface::OUTPUT_RAW);

                return 0;
            }
            $this->services->pager($output)->show($text, $this->services->isInteractive($input));

            return 0;
        }

        $entries = $history->entries($site, $failed);
        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode(['schema' => 1, 'site' => $site, 'entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return 0;
        }
        if ($entries === []) {
            $output->writeln($failed ? "{$site} has no failed operations." : "{$site} has no history yet.");

            return 0;
        }
        self::table($output, $entries, $this->services->clock()->now(), $this->services->theme());

        return 0;
    }

    /**
     * The §9.5.12 table: when, action, result, release, duration (and site when present).
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function table(OutputInterface $output, array $entries, DateTimeImmutable $now, Theme $theme): void
    {
        $withSite = isset($entries[0]['site']);
        $table = new Table($output);
        $table->setHeaders([...($withSite ? ['Site'] : []), 'When', 'Action', 'Result', 'Release', 'Took', 'Log']);
        foreach ($entries as $entry) {
            $when = is_string($entry['ts'] ?? null) ? self::when($entry['ts'], $now) : '—';
            $result = is_string($entry['result'] ?? null) ? $entry['result'] : '?';
            $table->addRow([
                ...($withSite ? [(string) $entry['site']] : []),
                $when,
                (string) ($entry['action'] ?? '?'),
                $theme->status(match ($result) {
                    'success' => 'ok',
                    'warning' => 'warn',
                    'failed', 'interrupted' => 'fail',
                    default => 'info',
                }) . ' ' . $result,
                (string) ($entry['release'] ?? '—'),
                is_int($entry['duration_s'] ?? null) ? Format::duration((float) $entry['duration_s']) : '—',
                is_string($entry['log'] ?? null) ? basename($entry['log'], '.log') : '—',
            ]);
        }
        $table->render();
    }

    private static function when(string $iso, DateTimeImmutable $now): string
    {
        try {
            return Format::relative(new DateTimeImmutable($iso), $now);
        } catch (\Exception) {
            return $iso;
        }
    }
}
