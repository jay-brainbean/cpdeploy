<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

/**
 * How services report progress without knowing the UI (ARC-04, ENG-03).
 * One step at a time: start() → line()* → succeed() | fail().
 */
interface Reporter
{
    public function start(string $label): void;

    /**
     * One line of output from the running step (already masked).
     */
    public function line(string $text): void;

    /**
     * Redraw hook while a command runs (spinners). May be a no-op.
     */
    public function tick(): void;

    public function succeed(string $detail = ''): void;

    /**
     * A warning, inside or outside a step. Does not end the step.
     */
    public function warn(string $text): void;

    /**
     * Ends the current step as failed. $logPath is shown under the last output lines (UI-09).
     */
    public function fail(string $error, ?string $logPath = null): void;

    /**
     * A plain informational line outside any step.
     */
    public function info(string $text): void;
}
