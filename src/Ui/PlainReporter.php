<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Cpdeploy\Support\Clock;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Non-TTY output (UI-08): one line per step, "[HH:MM:SS] ✓ Composer (38s)".
 * No cursor movement, no spinners. Command output appears only with -v.
 */
final class PlainReporter implements Reporter
{
    private ?string $label = null;
    private float $startedAt = 0.0;

    /** @var list<string> */
    private array $lines = [];

    public function __construct(
        private readonly OutputInterface $output,
        private readonly Theme $theme,
        private readonly Clock $clock,
    ) {
    }

    public function start(string $label): void
    {
        $this->label = $label;
        $this->startedAt = microtime(true);
        $this->lines = [];
        if ($this->output->isVerbose()) {
            $this->write(sprintf('%s %s', $this->theme->symbol('arrow'), $label));
        }
    }

    public function line(string $text): void
    {
        $this->lines[] = $text;
        if (count($this->lines) > 200) {
            array_shift($this->lines);
        }
        if ($this->output->isVerbose()) {
            $this->output->writeln('    ' . $text, OutputInterface::OUTPUT_RAW);
        }
    }

    public function tick(): void
    {
    }

    public function succeed(string $detail = ''): void
    {
        $this->write(sprintf(
            '<fg=green>%s</> %s (%s)%s',
            $this->theme->symbol('ok'),
            $this->label ?? '',
            Format::duration(microtime(true) - $this->startedAt),
            $detail !== '' ? ' · ' . $detail : '',
        ));
        $this->label = null;
    }

    public function warn(string $text): void
    {
        $this->write(sprintf('<fg=yellow>%s</> %s', $this->theme->symbol('warn'), $text));
    }

    public function fail(string $error, ?string $logPath = null): void
    {
        $this->write(sprintf(
            '<fg=red>%s</> %s%s — %s',
            $this->theme->symbol('fail'),
            $this->label ?? '',
            $this->label !== null ? ' (' . Format::duration(microtime(true) - $this->startedAt) . ')' : '',
            $error,
        ));
        foreach (array_slice($this->lines, -15) as $line) {
            $this->output->writeln('    ' . $line, OutputInterface::OUTPUT_RAW);
        }
        if ($logPath !== null) {
            $this->output->writeln('    Log: ' . $logPath);
        }
        $this->label = null;
    }

    public function info(string $text): void
    {
        $this->write($text);
    }

    private function write(string $text): void
    {
        $this->output->writeln(sprintf('[%s] %s', $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i:s'), $text));
    }
}
