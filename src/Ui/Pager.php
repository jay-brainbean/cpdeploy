<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows long text: `less -R` when available on a TTY, else the last 200 lines.
 */
final class Pager
{
    public const FALLBACK_LINES = 200;

    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly OutputInterface $output,
    ) {
    }

    public function show(string $text, bool $interactive): void
    {
        $less = $interactive ? $this->shell->which('less') : null;
        if ($less !== null) {
            $file = $this->fs->tempFile('pager', $text);
            try {
                $this->shell->run([$less, '-R', $file], (new RunOptions(timeout: null))->tty());
            } finally {
                @unlink($file);
            }

            return;
        }

        $lines = explode("\n", rtrim($text, "\n"));
        if (count($lines) > self::FALLBACK_LINES) {
            $this->output->writeln(sprintf('(showing the last %d of %d lines)', self::FALLBACK_LINES, count($lines)));
            $lines = array_slice($lines, -self::FALLBACK_LINES);
        }
        foreach ($lines as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }
}
