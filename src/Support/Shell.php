<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Process\Process;

/**
 * The only way the tool runs external programs (ARC-05, §7.17).
 *
 * - Commands are argument arrays; a pipeline goes through pipeline() (SH-01).
 * - The environment is a fixed base plus additions, never the caller's full
 *   environment (SH-03).
 * - Commands start in their own process group so a timeout or Ctrl+C can stop
 *   everything they spawned (SH-05).
 */
final class Shell
{
    /** Seconds between SIGTERM and SIGKILL when stopping a process group (SH-05). */
    public const KILL_GRACE = 10.0;

    /** Variables passed through from the tool's environment besides the base (SH-03). */
    private const PASS_THROUGH = [
        'USER', 'LOGNAME', 'SHELL', 'TERM', 'COLUMNS', 'LINES', 'TZ', 'TMPDIR',
        'http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY',
        'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE', 'NODE_EXTRA_CA_CERTS',
        'COMPOSER_CAFILE', 'GIT_SSL_CAINFO',
    ];

    private ?string $setsid = null;
    private bool $setsidResolved = false;
    private float $killGrace = self::KILL_GRACE;

    public function __construct(
        private readonly Environment $environment,
        private readonly Masker $masker,
        private readonly Signals $signals,
        private ?Log $log = null,
    ) {
    }

    /**
     * Sends every later command and its output to $log (SH-04), for the rest of
     * the operation; null detaches it. Every service shares this runner, so one
     * call covers them all.
     */
    public function attachLog(?Log $log): void
    {
        $this->log = $log;
    }

    public function log(): ?Log
    {
        return $this->log;
    }

    /**
     * A copy of this runner that also writes to the given operation log (SH-04).
     */
    public function withLog(?Log $log): self
    {
        $copy = new self($this->environment, $this->masker, $this->signals, $log);
        $copy->killGrace = $this->killGrace;

        return $copy;
    }

    /**
     * Test hook: shorten the SIGTERM → SIGKILL grace period.
     */
    public function withKillGrace(float $seconds): self
    {
        $copy = new self($this->environment, $this->masker, $this->signals, $this->log);
        $copy->killGrace = $seconds;

        return $copy;
    }

    /**
     * @param list<string> $command
     */
    public function run(array $command, RunOptions $options = new RunOptions()): ProcessResult
    {
        if ($command === []) {
            throw new \InvalidArgumentException('Empty command');
        }

        $argv = $command;
        // Own process group only when we can catch Ctrl+C ourselves: without pcntl,
        // the terminal's SIGINT must reach the children directly (docs/decisions.md).
        $setsid = $options->mode === RunOptions::TTY || !Signals::supported() ? null : $this->setsid();
        if ($setsid !== null) {
            array_unshift($argv, $setsid);
        }

        $process = new Process($argv, $options->cwd, $this->buildEnv($options), $options->input, null);
        if ($options->mode === RunOptions::TTY) {
            $process->setTty(Process::isTtySupported());
        }

        $this->log?->write(sprintf(
            '$ %s%s  (timeout %s)',
            $this->masker->maskCommand($command),
            $options->cwd !== null ? '  [in ' . $options->cwd . ']' : '',
            $options->timeout === null ? 'none' : (int) $options->timeout . 's',
        ));

        $stdout = '';
        $stderr = '';
        $pending = ['out' => '', 'err' => ''];
        $timedOut = false;
        $cancelled = false;
        $started = hrtime(true);

        $collect = function () use ($process, &$stdout, &$stderr, &$pending, $options): void {
            foreach (['out' => $process->getIncrementalOutput(), 'err' => $process->getIncrementalErrorOutput()] as $stream => $chunk) {
                if ($chunk === '') {
                    continue;
                }
                if ($stream === 'out') {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                $pending[$stream] .= $chunk;
                while (($pos = strcspn($pending[$stream], "\r\n")) < strlen($pending[$stream])) {
                    $this->emitLine(substr($pending[$stream], 0, $pos), $options);
                    $pending[$stream] = (string) substr($pending[$stream], $pos + 1);
                }
            }
        };

        if ($options->mode === RunOptions::TTY) {
            // Interactive passthrough (editor, tinker): the user controls the process.
            $exit = $process->run();
            $duration = (hrtime(true) - $started) / 1e9;
            $this->log?->write(sprintf('  → exit %d in %.1fs (interactive)', $exit, $duration));

            return new ProcessResult($command, $exit, '', '', $duration);
        }

        $process->start();

        $lastTick = 0.0;
        while ($process->isRunning()) {
            $collect();
            if ($options->onTick !== null && microtime(true) - $lastTick >= 0.1) {
                $lastTick = microtime(true);
                ($options->onTick)();
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            if ($options->timeout !== null && $elapsed > $options->timeout) {
                $timedOut = true;
                $this->stop($process, $setsid !== null);
                break;
            }
            if ($this->signals->cancelRequested()) {
                $cancelled = true;
                $this->stop($process, $setsid !== null);
                break;
            }
            usleep(20_000);
        }

        $collect();
        foreach ($pending as $rest) {
            if ($rest !== '') {
                $this->emitLine($rest, $options);
            }
        }

        $exit = $process->getExitCode();
        if ($exit === null) {
            $exit = $process->getTermSignal() > 0 ? 128 + $process->getTermSignal() : 1;
        } elseif ($process->hasBeenSignaled()) {
            $exit = 128 + $process->getTermSignal();
        }

        $duration = (hrtime(true) - $started) / 1e9;
        $this->log?->write(sprintf(
            '  → exit %d in %.1fs%s%s',
            $exit,
            $duration,
            $timedOut ? ' (timed out)' : '',
            $cancelled ? ' (cancelled)' : '',
        ));

        return new ProcessResult($command, $exit, $stdout, $stderr, $duration, $timedOut, $cancelled);
    }

    /**
     * Runs and throws when the command fails. Timeouts, cancels, OOM and full disks
     * map to their own error codes; anything else to $code.
     *
     * @param list<string> $command
     */
    public function mustRun(
        array $command,
        RunOptions $options,
        ErrorCode $code,
        string $message,
        string $hint = 'See the log for details.',
    ): ProcessResult {
        $result = $this->run($command, $options);
        if (!$result->successful()) {
            throw $this->failure($result, $options, $code, $message, $hint);
        }

        return $result;
    }

    public function failure(
        ProcessResult $result,
        RunOptions $options,
        ErrorCode $code,
        string $message,
        string $hint = 'See the log for details.',
    ): CpdeployException {
        $step = $options->label !== '' ? $options->label : ($result->command[0] ?? 'command');

        if ($result->cancelled) {
            return new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', 'Your live site was not changed.');
        }
        if ($result->timedOut) {
            return new CpdeployException(
                ErrorCode::TIMEOUT,
                sprintf('%s took longer than %ds and was stopped', $step, (int) $options->timeout),
                'Raise the matching timeout in Settings (config.yml → timeouts).',
            );
        }
        if ($result->isOutOfMemory()) {
            return new CpdeployException(
                ErrorCode::OOM,
                'The build ran out of memory (CloudLinux/LVE limits are common on cPanel)',
                'Raise the account\'s memory limit, set Node max memory in Manage site → Node, or build in CI.',
            );
        }
        if ($result->isDiskFull()) {
            return new CpdeployException(
                ErrorCode::DISK,
                'The disk or quota is full',
                'Free space, lower releases.keep, or delete old releases.',
            );
        }

        return new CpdeployException($code, $message, $hint);
    }

    /**
     * Runs a pipeline through `bash -o pipefail -c` (SH-01). The script MUST be
     * built only from quote()d arguments.
     */
    public function pipeline(string $script, RunOptions $options = new RunOptions()): ProcessResult
    {
        return $this->run(['bash', '-o', 'pipefail', '-c', $script], $options);
    }

    public static function quote(string $argument): string
    {
        if ($argument !== '' && preg_match('/^[A-Za-z0-9_\/.,:=+@%^-]+$/', $argument) === 1) {
            return $argument;
        }

        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /**
     * Looks a program up on the base PATH (plus optional prefixes).
     *
     * @param list<string> $pathPrefix
     */
    public function which(string $program, array $pathPrefix = []): ?string
    {
        if (str_contains($program, '/')) {
            return is_file($program) && is_executable($program) ? $program : null;
        }
        foreach (explode(':', $this->basePath($pathPrefix)) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $program;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * SH-03 PATH: prefixes + ~/bin:/usr/local/bin:/usr/bin:/bin. In test mode the
     * fake-binaries folder from CPDEPLOY_TEST_PATH goes first.
     *
     * @param list<string> $pathPrefix
     */
    public function basePath(array $pathPrefix = []): string
    {
        $parts = $pathPrefix;
        $testPath = $this->environment->testing('CPDEPLOY_TEST_PATH');
        if ($testPath !== null) {
            $parts[] = $testPath;
        }
        $parts[] = $this->environment->home() . '/bin';
        array_push($parts, '/usr/local/bin', '/usr/bin', '/bin');

        return implode(':', array_values(array_unique($parts)));
    }

    /**
     * @return array<string, string|false>
     */
    private function buildEnv(RunOptions $options): array
    {
        // Start by removing everything inherited, then add the base (SH-03).
        $env = [];
        foreach (array_keys(getenv() + $_ENV) as $key) {
            $env[(string) $key] = false;
        }

        foreach (self::PASS_THROUGH as $key) {
            $value = $this->environment->get($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }
        if ($this->environment->isTesting()) {
            foreach ($this->environment->all() as $key => $value) {
                if (str_starts_with($key, 'CPDEPLOY_') || str_starts_with($key, 'CPD_')) {
                    $env[$key] = $value;
                }
            }
        }

        $env['HOME'] = $this->environment->home();
        $env['PATH'] = $this->basePath($options->pathPrefix);
        $lang = $this->environment->get('LANG');
        $lcAll = $this->environment->get('LC_ALL');
        $env['LANG'] = $lang ?? 'C.UTF-8';
        $env['LC_ALL'] = $lcAll ?? ($lang ?? 'C.UTF-8');
        $env['GIT_TERMINAL_PROMPT'] = '0';

        foreach ($options->env as $key => $value) {
            $env[$key] = $value;
        }
        // Never inherit or pass a repository override (SH-03).
        $env['GIT_DIR'] = false;
        $env['GIT_WORK_TREE'] = false;

        return $env;
    }

    private function emitLine(string $line, RunOptions $options): void
    {
        $masked = $this->masker->mask($line);
        $this->log?->write('  ' . $masked);
        if ($options->onLine !== null && $options->mode === RunOptions::STREAM) {
            ($options->onLine)($masked);
        }
    }

    /**
     * SH-05: SIGTERM to the process group, SIGKILL after the grace period.
     */
    private function stop(Process $process, bool $ownGroup): void
    {
        $pid = $process->getPid();
        if ($ownGroup && $pid !== null && function_exists('posix_kill')) {
            posix_kill(-$pid, SIGTERM);
            $deadline = microtime(true) + $this->killGrace;
            while ($process->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }
            // Kill any remaining members of the group, even if the leader exited.
            posix_kill(-$pid, SIGKILL);
            $deadline = microtime(true) + 5;
            while ($process->isRunning() && microtime(true) < $deadline) {
                usleep(20_000);
            }

            return;
        }
        $process->stop($this->killGrace);
    }

    private function setsid(): ?string
    {
        if (!$this->setsidResolved) {
            $this->setsidResolved = true;
            foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
                if (is_executable($candidate)) {
                    $this->setsid = $candidate;
                    break;
                }
            }
        }

        return $this->setsid;
    }
}
