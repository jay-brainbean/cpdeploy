<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Signals;
use Laravel\Prompts\Terminal;
use RuntimeException;

/**
 * Laravel Prompts' terminal, made safe for a closed terminal. Prompts retries an
 * empty read forever, so once the SSH session or cPanel Terminal tab is gone a
 * prompt spins at 100% CPU; and since Signals catches SIGHUP/SIGTERM, nothing
 * would stop it. Here a read waits in short slices, and end of input or a
 * SIGHUP/SIGTERM ends the prompt with E_CANCELLED (flows clean up as for Ctrl+C);
 * PromptsAsker then exits at the next prompt.
 */
final class PromptTerminal extends Terminal
{
    /** Empty reads in a row without end of file before the input counts as gone. */
    private const EMPTY_READS = 50;

    public function __construct(private readonly Signals $signals)
    {
        parent::__construct();
    }

    public function read(): string
    {
        $empty = 0;
        while (true) {
            if ($this->signals->terminating() !== null) {
                throw $this->stopped();
            }
            $read = [STDIN];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200_000);
            if ($ready === 0) {
                continue;
            }
            if ($ready === false) {
                // Interrupted by a signal: loop to look at it.
                usleep(10_000);
                continue;
            }
            $input = @fread(STDIN, 1024);
            if (is_string($input) && $input !== '') {
                return $input;
            }
            if (feof(STDIN) || ++$empty >= self::EMPTY_READS) {
                $this->signals->terminalLost();

                throw $this->stopped();
            }
        }
    }

    /**
     * `stty` fails once the terminal is gone; Prompts calls this from a destructor,
     * where an exception would replace the real error.
     */
    public function restoreTty(): void
    {
        try {
            parent::restoreTty();
        } catch (RuntimeException) {
        }
    }

    private function stopped(): CpdeployException
    {
        $why = $this->signals->terminating() === 15 ? 'cpdeploy was asked to stop (SIGTERM)' : 'the terminal was closed';

        return new CpdeployException(ErrorCode::CANCELLED, "Stopped: {$why}", 'Nothing more was changed.');
    }
}
