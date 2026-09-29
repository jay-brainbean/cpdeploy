<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Outcome of one external command. Output is raw (unmasked); anything shown or
 * logged goes through the Masker first.
 */
final class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $duration,
        public readonly bool $timedOut = false,
        public readonly bool $cancelled = false,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->cancelled;
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }

    /**
     * SH-06: exit 137, "Killed", or a V8 heap exhaustion message.
     */
    public function isOutOfMemory(): bool
    {
        if ($this->successful() || $this->timedOut || $this->cancelled) {
            return false;
        }
        $out = $this->output();

        return $this->exitCode === 137
            || preg_match('/^\s*Killed\s*$/m', $out) === 1
            || str_contains($out, 'JavaScript heap out of memory');
    }

    /**
     * SH-07: quota or disk full.
     */
    public function isDiskFull(): bool
    {
        if ($this->successful()) {
            return false;
        }
        $out = $this->output();

        return str_contains($out, 'Disk quota exceeded') || str_contains($out, 'No space left on device');
    }

    /**
     * Last $count non-empty output lines (UI-09).
     *
     * @return list<string>
     */
    public function lastLines(int $count = 15): array
    {
        $lines = preg_split('/\r\n|\r|\n/', rtrim($this->output())) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        return array_slice($lines, -$count);
    }
}
