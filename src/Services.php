<?php

declare(strict_types=1);

namespace Cpdeploy;

use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\Signals;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\NonInteractiveAsker;
use Cpdeploy\Ui\Pager;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\PromptsAsker;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Ui\TaskReporter;
use Cpdeploy\Ui\Theme;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hand-written factory that wires every service (ARC-07). Objects are created on
 * first use and shared for the rest of the run.
 */
final class Services
{
    private ?Paths $paths = null;
    private ?Clock $clock = null;
    private ?Masker $masker = null;
    private ?Signals $signals = null;
    private ?Shell $shell = null;
    private ?Fs $fs = null;
    private ?GlobalConfig $config = null;
    private ?SystemInfo $system = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function paths(): Paths
    {
        return $this->paths ??= Paths::fromEnvironment($this->environment);
    }

    public function clock(): Clock
    {
        return $this->clock ??= Clock::fromEnvironment($this->environment);
    }

    public function masker(): Masker
    {
        return $this->masker ??= new Masker();
    }

    public function signals(): Signals
    {
        return $this->signals ??= new Signals();
    }

    public function shell(): Shell
    {
        return $this->shell ??= new Shell($this->environment, $this->masker(), $this->signals());
    }

    public function fs(): Fs
    {
        return $this->fs ??= new Fs($this->paths(), $this->shell());
    }

    public function config(): GlobalConfig
    {
        return $this->config ??= GlobalConfig::load($this->paths()->configFile(), $this->fs());
    }

    public function system(): SystemInfo
    {
        return $this->system ??= new SystemInfo($this->environment);
    }

    public function serverCheck(): ServerCheck
    {
        return new ServerCheck($this->shell(), $this->system());
    }

    public function theme(): Theme
    {
        $configured = null;
        if ($this->config !== null || is_file($this->paths()->configFile())) {
            try {
                $configured = $this->config()->uiFlag('unicode');
            } catch (\Throwable) {
                $configured = null;
            }
        }

        return Theme::detect($this->environment, $configured);
    }

    /**
     * NI-01: interactive unless -n, or STDIN/STDOUT is not a TTY.
     */
    public function isInteractive(InputInterface $input): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }

        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    public function asker(InputInterface $input): Asker
    {
        if ($this->isInteractive($input)) {
            return new PromptsAsker();
        }
        $yes = $input->hasOption('yes') && $input->getOption('yes') === true;

        return new NonInteractiveAsker($yes);
    }

    /**
     * TaskReporter on a TTY; PlainReporter otherwise. With --json, progress goes to
     * stderr so stdout holds only the JSON document (NI-05).
     */
    public function reporter(InputInterface $input, OutputInterface $output): Reporter
    {
        $json = $input->hasOption('json') && $input->getOption('json') === true;
        $target = $json && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (!$json && $this->isInteractive($input) && $output->isDecorated()) {
            return new TaskReporter($target, $this->theme());
        }

        return new PlainReporter($target, $this->theme(), $this->clock());
    }

    public function pager(OutputInterface $output): Pager
    {
        return new Pager($this->shell(), $this->fs(), $output);
    }
}
