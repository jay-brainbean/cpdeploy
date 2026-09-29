<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

/**
 * Test double: records every event.
 */
final class MemoryReporter implements Reporter
{
    /** @var list<array{0: string, 1: string}> */
    public array $events = [];

    public function start(string $label): void
    {
        $this->events[] = ['start', $label];
    }

    public function line(string $text): void
    {
        $this->events[] = ['line', $text];
    }

    public function tick(): void
    {
    }

    public function succeed(string $detail = ''): void
    {
        $this->events[] = ['succeed', $detail];
    }

    public function warn(string $text): void
    {
        $this->events[] = ['warn', $text];
    }

    public function fail(string $error, ?string $logPath = null): void
    {
        $this->events[] = ['fail', $error];
    }

    public function info(string $text): void
    {
        $this->events[] = ['info', $text];
    }

    /**
     * @return list<string>
     */
    public function of(string $type): array
    {
        $out = [];
        foreach ($this->events as [$t, $text]) {
            if ($t === $type) {
                $out[] = $text;
            }
        }

        return $out;
    }

    public function allText(): string
    {
        return implode("\n", array_map(static fn (array $e): string => $e[1], $this->events));
    }
}
