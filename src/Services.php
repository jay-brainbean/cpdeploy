<?php

declare(strict_types=1);

namespace Cpdeploy;

use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Check\ServerCheckServices;
use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Cpanel\MysqlService;
use Cpdeploy\Cpanel\QuotaService;
use Cpdeploy\Cpanel\Uapi;
use Cpdeploy\Runtime\ComposerInstaller;
use Cpdeploy\Runtime\NodeInstaller;
use Cpdeploy\Runtime\NodeLocator;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Runtime\PhpLocator;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Http;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\Signals;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Support\TcpProbe;
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
    private ?Http $http = null;
    private ?Uapi $uapi = null;
    private ?DomainService $domains = null;
    private ?MultiPhpService $multiPhp = null;
    private ?PhpLocator $phpLocator = null;
    private ?NodeLocator $nodeLocator = null;

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

    /**
     * CPDEPLOY_HTTP_NO_BACKOFF=1 (test mode only) skips the waits between retries.
     */
    public function http(): Http
    {
        $noWait = $this->environment->testing('CPDEPLOY_HTTP_NO_BACKOFF') === '1'
            ? static function (int $seconds): void {
            }
        : null;

        return $this->http ??= new Http($this->shell(), $this->fs(), $noWait);
    }

    public function uapi(): Uapi
    {
        return $this->uapi ??= new Uapi($this->shell(), $this->system()->uapiBinary());
    }

    public function domains(): DomainService
    {
        return $this->domains ??= new DomainService($this->uapi());
    }

    public function multiPhp(): MultiPhpService
    {
        return $this->multiPhp ??= new MultiPhpService($this->uapi());
    }

    public function mysql(): MysqlService
    {
        return new MysqlService($this->uapi());
    }

    public function quota(): QuotaService
    {
        return new QuotaService($this->uapi());
    }

    /**
     * CPDEPLOY_SYSTEM_ROOT (test mode only) points the /etc, /opt and /usr/local
     * lookups at a fake tree.
     */
    public function cloudLinux(): CloudLinux
    {
        return new CloudLinux($this->systemRoot());
    }

    public function systemRoot(): string
    {
        return rtrim($this->environment->testing('CPDEPLOY_SYSTEM_ROOT') ?? '', '/');
    }

    /**
     * CPDEPLOY_PHP_SEARCH_PATHS (test mode only): colon-separated folders scanned
     * for both ea-phpNN/root/usr/bin/php and phpNN/usr/bin/php.
     */
    public function phpLocator(): PhpLocator
    {
        if ($this->phpLocator === null) {
            $override = $this->environment->testing('CPDEPLOY_PHP_SEARCH_PATHS');
            $roots = $override !== null ? array_values(array_filter(explode(':', $override))) : null;
            $this->phpLocator = new PhpLocator(
                $this->shell(),
                $this->uapi()->available() ? $this->multiPhp() : null,
                $roots ?? ['/opt/cpanel'],
                $roots ?? ['/opt/alt'],
            );
        }

        return $this->phpLocator;
    }

    public function php(): PhpService
    {
        return new PhpService($this->phpLocator(), $this->multiPhp(), $this->cloudLinux(), $this->shell());
    }

    public function composerInstaller(): ComposerInstaller
    {
        return new ComposerInstaller($this->http(), $this->fs(), $this->paths(), $this->config()->mirror('composer'));
    }

    /**
     * CPDEPLOY_NODE_SEARCH_PATHS (test mode only): colon-separated glob patterns of
     * bin folders that replace the ea-nodejs, alt-nodejs and nvm locations.
     */
    public function nodeLocator(): NodeLocator
    {
        if ($this->nodeLocator === null) {
            $toolsPattern = $this->paths()->nodeDir() . '/node-v*-linux-*/bin';
            $override = $this->environment->testing('CPDEPLOY_NODE_SEARCH_PATHS');
            $patterns = $override !== null
                ? [...array_values(array_filter(explode(':', $override))), $toolsPattern]
                : NodeLocator::defaultPatterns($this->paths()->home(), $this->paths()->nodeDir());
            $this->nodeLocator = new NodeLocator($this->shell(), $patterns);
        }

        return $this->nodeLocator;
    }

    public function nodeResolver(): NodeResolver
    {
        return new NodeResolver($this->nodeLocator(), $this->http(), $this->fs(), $this->paths(), $this->config()->mirror('node'));
    }

    /**
     * CPDEPLOY_ARCH and CPDEPLOY_GLIBC (test mode only) replace uname -m and the glibc probe.
     */
    public function nodeInstaller(): NodeInstaller
    {
        return new NodeInstaller(
            $this->http(),
            $this->fs(),
            $this->shell(),
            $this->paths(),
            $this->config()->mirror('node'),
            $this->environment->testing('CPDEPLOY_ARCH'),
            $this->environment->testing('CPDEPLOY_GLIBC'),
        );
    }

    public function packageManager(): PackageManager
    {
        return new PackageManager($this->shell(), $this->fs(), $this->paths());
    }

    public function serverCheck(): ServerCheck
    {
        return new ServerCheck($this->shell(), $this->system(), new ServerCheckServices(
            $this->uapi(),
            $this->domains(),
            $this->multiPhp(),
            $this->mysql(),
            $this->quota(),
            $this->cloudLinux(),
            $this->phpLocator(),
            TcpProbe::fromEnvironment($this->environment),
            $this->config(),
            is_file($this->paths()->tokenFile()),
        ));
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
