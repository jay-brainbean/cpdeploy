<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Ctrl+C / SIGTERM / SIGHUP handling (LCK-04). With pcntl the signals only set a
 * "cancel requested" flag that long-running code polls. Inside a critical section
 * (go-live G1–G8) the flag is still recorded but reported only once the section ends.
 * Without pcntl the process simply dies and the state file drives recovery.
 *
 * SIGHUP (the terminal closed) and SIGTERM also mark the process as
 * terminating: that is never reset, so the next prompt exits instead of waiting
 * for an answer nobody can give (see PromptTerminal).
 */
final class Signals
{
    private bool $requested = false;
    private int $count = 0;
    private float $lastAt = 0.0;
    private int $criticalDepth = 0;
    private bool $installed = false;
    private ?int $terminating = null;

    public function install(): void
    {
        if ($this->installed || !self::supported()) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function (int $signo): void {
                $this->request($signo);
            });
        }
        $this->installed = true;
    }

    public static function supported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }

    /**
     * @param int $signal 2 = SIGINT, 1 = SIGHUP, 15 = SIGTERM (literal: the constants need pcntl)
     */
    public function request(int $signal = 2): void
    {
        if ($signal === 1 || $signal === 15) {
            $this->terminating ??= $signal;
        }
        $now = microtime(true);
        $this->count = ($now - $this->lastAt) <= 3.0 ? $this->count + 1 : 1;
        $this->lastAt = $now;
        $this->requested = true;
    }

    /**
     * True when the user asked to cancel and we are not inside a critical section,
     * or pressed Ctrl+C twice within 3 s inside one (GL-01 force stop).
     */
    public function cancelRequested(): bool
    {
        if (!$this->requested) {
            return false;
        }

        return $this->criticalDepth === 0 || $this->forceRequested();
    }

    public function forceRequested(): bool
    {
        return $this->requested && $this->count >= 2 && (microtime(true) - $this->lastAt) <= 3.0;
    }

    public function pendingInCritical(): bool
    {
        return $this->requested && $this->criticalDepth > 0;
    }

    /**
     * @template T
     * @param callable(): T $section
     * @return T
     */
    public function critical(callable $section): mixed
    {
        $this->criticalDepth++;
        try {
            return $section();
        } finally {
            $this->criticalDepth--;
        }
    }

    /**
     * The signal (SIGHUP or SIGTERM) that told the process to stop, or null.
     */
    public function terminating(): ?int
    {
        return $this->terminating;
    }

    /**
     * The terminal went away without a SIGHUP (its input reached end of file).
     */
    public function terminalLost(): void
    {
        $this->request(1);
    }

    /**
     * Forgets a Ctrl+C once the operation it cancelled is over, so the next one
     * isn't cancelled straight away. A hang-up or SIGTERM is kept.
     */
    public function reset(): void
    {
        if ($this->terminating !== null) {
            return;
        }
        $this->requested = false;
        $this->count = 0;
    }
}
