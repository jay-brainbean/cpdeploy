<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Interactive step output (ARC-01): a spinner line with the elapsed time and the
 * last 10 output lines dimmed beneath it; when the step ends the block collapses
 * to "✓ Step  12s". Implemented here rather than with Laravel Prompts' task(),
 * which forks a renderer and replaces the SIGINT handler (see docs/decisions.md).
 *
 * The spinner advances on tick(), which Shell calls while a command runs, so it
 * animates without pcntl (ARC-02).
 */
final class TaskReporter implements Reporter
{
    private const VISIBLE_LINES = 10;

    private ?ConsoleSectionOutput $section = null;
    private ?string $label = null;
    private float $startedAt = 0.0;
    private int $frame = 0;
    private float $lastDraw = 0.0;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> warnings raised during the current step, kept after it collapses */
    private array $stepWarnings = [];

    public function __construct(
        private readonly OutputInterface $output,
        private readonly Theme $theme,
    ) {
    }

    public function start(string $label): void
    {
        $this->label = $label;
        $this->startedAt = microtime(true);
        $this->lines = [];
        $this->stepWarnings = [];
        $this->frame = 0;
        $this->section = $this->output instanceof ConsoleOutputInterface ? $this->output->section() : null;
        $this->draw(true);
    }

    public function line(string $text): void
    {
        $this->lines[] = $text;
        if (count($this->lines) > 200) {
            array_shift($this->lines);
        }
        $this->draw();
    }

    public function tick(): void
    {
        $this->draw();
    }

    public function succeed(string $detail = ''): void
    {
        $this->finish(sprintf(
            '<fg=green>%s</> %s  <fg=gray>%s</>%s',
            $this->theme->symbol('ok'),
            $this->label ?? '',
            Format::duration(microtime(true) - $this->startedAt),
            $detail !== '' ? '  <fg=gray>' . $detail . '</>' : '',
        ));
    }

    public function warn(string $text): void
    {
        if ($this->label !== null) {
            // Inside a step: keep it with the step's output so the block stays intact.
            $this->lines[] = $this->theme->symbol('warn') . ' ' . $text;
            $this->stepWarnings[] = sprintf('  <fg=yellow>%s</> %s', $this->theme->symbol('warn'), $text);
            $this->draw(true);

            return;
        }
        $this->output->writeln(sprintf('<fg=yellow>%s</> %s', $this->theme->symbol('warn'), $text));
    }

    public function fail(string $error, ?string $logPath = null): void
    {
        $width = $this->width() - 4;
        $body = [];
        foreach (array_slice($this->lines, -15) as $line) {
            $body[] = '    <fg=gray>' . $this->escape(Format::truncate($line, $width, '…')) . '</>';
        }
        if ($logPath !== null) {
            $body[] = '    Log: ' . $logPath;
        }
        $head = sprintf(
            '<fg=red>%s</> %s  <fg=gray>%s</>  %s',
            $this->theme->symbol('fail'),
            $this->label ?? '',
            Format::duration(microtime(true) - $this->startedAt),
            $error,
        );
        $this->finish(implode("\n", array_merge([$head], $body)));
    }

    public function info(string $text): void
    {
        $this->output->writeln($text);
    }

    private function finish(string $text): void
    {
        if ($this->stepWarnings !== []) {
            $text .= "\n" . implode("\n", $this->stepWarnings);
            $this->stepWarnings = [];
        }
        if ($this->section !== null) {
            $this->section->overwrite($text);
        } else {
            $this->output->writeln($text);
        }
        $this->section = null;
        $this->label = null;
    }

    private function draw(bool $force = false): void
    {
        if ($this->label === null) {
            return;
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastDraw < 0.08) {
            return;
        }
        $this->lastDraw = $now;
        $this->frame++;

        if ($this->section === null) {
            // Not a console with sections: print the label once, nothing animated.
            if ($force && $this->frame === 1) {
                $this->output->writeln($this->theme->spinnerFrame(0) . ' ' . $this->label);
            }

            return;
        }

        $width = $this->width() - 4;
        $text = [sprintf(
            '<fg=cyan>%s</> %s  <fg=gray>%s</>',
            $this->theme->spinnerFrame($this->frame),
            $this->label,
            Format::duration($now - $this->startedAt),
        )];
        foreach (array_slice($this->lines, -self::VISIBLE_LINES) as $line) {
            $text[] = '    <fg=gray>' . $this->escape(Format::truncate($line, $width, '…')) . '</>';
        }
        $this->section->overwrite(implode("\n", $text));
    }

    private function width(): int
    {
        return max(40, (new Terminal())->getWidth());
    }

    private function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
