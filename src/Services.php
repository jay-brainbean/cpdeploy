<?php

declare(strict_types=1);

namespace Cpdeploy;

use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Check\ServerCheckServices;
use Cpdeploy\Check\SiteCheck;
use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\Presets;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Config\SiteSettings;
use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Cpanel\MysqlService;
use Cpdeploy\Cpanel\QuotaService;
use Cpdeploy\Cpanel\Uapi;
use Cpdeploy\Database\DatabaseService;
use Cpdeploy\Database\DbCheck;
use Cpdeploy\Deploy\Builder;
use Cpdeploy\Deploy\ChangeAnalyzer;
use Cpdeploy\Deploy\Deployer;
use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Deploy\GoLive;
use Cpdeploy\Deploy\HealthChecker;
use Cpdeploy\Deploy\History;
use Cpdeploy\Deploy\PlanBuilder;
use Cpdeploy\Deploy\Preflight;
use Cpdeploy\Deploy\Recovery;
use Cpdeploy\Deploy\ReleaseActions;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Deploy\ReleaseManifest;
use Cpdeploy\Deploy\Rollback;
use Cpdeploy\Deploy\SiteInfo;
use Cpdeploy\Deploy\SiteRemover;
use Cpdeploy\Deploy\SiteStatus;
use Cpdeploy\Deploy\StepRunner;
use Cpdeploy\Deploy\Steps\ComposerStep;
use Cpdeploy\Deploy\Steps\DocrootFilesStep;
use Cpdeploy\Deploy\Steps\ExportStep;
use Cpdeploy\Deploy\Steps\FrontendBuildStep;
use Cpdeploy\Deploy\Steps\LinkSharedStep;
use Cpdeploy\Deploy\Steps\OptimizeStep;
use Cpdeploy\Deploy\Steps\QueueRestartStep;
use Cpdeploy\Deploy\Steps\SeedStep;
use Cpdeploy\Deploy\Steps\StorageLinkStep;
use Cpdeploy\Docroot\DocrootDetach;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Env\EnvManager;
use Cpdeploy\Git\DeployKeyService;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Git\HostKeyRefresh;
use Cpdeploy\Git\HostKeys;
use Cpdeploy\Git\MirrorService;
use Cpdeploy\Git\SiteKeys;
use Cpdeploy\Git\Transport;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\GitHub\TokenService;
use Cpdeploy\GitHub\TokenStore;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Laravel\LaravelTools;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Laravel\MigrationStatus;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Project\ProjectDetector;
use Cpdeploy\Runtime\ComposerAuth;
use Cpdeploy\Runtime\ComposerInstaller;
use Cpdeploy\Runtime\NodeInstaller;
use Cpdeploy\Runtime\NodeLocator;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Runtime\PhpChange;
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
use Cpdeploy\Ui\Editor;
use Cpdeploy\Ui\NonInteractiveAsker;
use Cpdeploy\Ui\Pager;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\PromptsAsker;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\TaskReporter;
use Cpdeploy\Ui\Theme;
use Cpdeploy\Update\SelfUpdate;
use Cpdeploy\Wizard\AddFromFile;
use Cpdeploy\Wizard\LegacyImporter;
use Cpdeploy\Wizard\RepoAccess;
use Cpdeploy\Wizard\SiteCreator;
use Cpdeploy\Wizard\SiteInspector;
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
    private ?Presets $presets = null;
    private ?SiteRegistry $sites = null;

    /** UIG-02: ~/cpdeploy was created by this run. */
    private bool $firstRun = false;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function markFirstRun(): void
    {
        $this->firstRun = true;
    }

    public function firstRun(): bool
    {
        return $this->firstRun;
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

    /**
     * Settings (§9.6): changes config.yml (validated) and what this process uses.
     *
     * @param array<string, mixed> $changes dotted key => value
     */
    public function changeConfig(array $changes): GlobalConfig
    {
        $config = $this->config();
        foreach ($changes as $path => $value) {
            $config = $config->with($path, $value);
        }
        $config->save($this->paths()->configFile(), $this->fs());

        return $this->config = $config;
    }

    public function presets(): Presets
    {
        return $this->presets ??= new Presets();
    }

    public function sites(): SiteRegistry
    {
        return $this->sites ??= new SiteRegistry($this->paths(), $this->fs(), $this->presets());
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

    public function git(): GitRepository
    {
        return new GitRepository($this->shell(), $this->fs(), (float) $this->config()->timeout('git'));
    }

    /**
     * CPDEPLOY_GIT_URL_OVERRIDE (test mode only) replaces every remote URL, e.g. file:///….
     */
    public function transport(): Transport
    {
        return new Transport(TcpProbe::fromEnvironment($this->environment), $this->environment->testing('CPDEPLOY_GIT_URL_OVERRIDE'));
    }

    public function hostKeys(): HostKeys
    {
        return new HostKeys($this->fs(), $this->paths()->knownHosts(), HostKeys::embeddedPath());
    }

    public function tokens(): TokenStore
    {
        return new TokenStore($this->paths(), $this->fs(), $this->masker());
    }

    /**
     * The GitHub API with the stored token (or $token). CPDEPLOY_GITHUB_API (test
     * mode only) replaces https://api.github.com.
     */
    public function github(?string $token = null): GitHubApi
    {
        return new GitHubApi(
            $this->http(),
            $this->masker(),
            rtrim($this->environment->testing('CPDEPLOY_GITHUB_API') ?? GitHubApi::DEFAULT_BASE, '/'),
            $token ?? $this->tokens()->get(),
            (float) $this->config()->timeout('http'),
        );
    }

    public function tokenService(): TokenService
    {
        return new TokenService($this->tokens(), fn (string $token): GitHubApi => $this->github($token));
    }

    public function deployKeys(): DeployKeyService
    {
        return new DeployKeyService($this->shell(), $this->paths(), $this->git(), $this->transport());
    }

    public function releases(): ReleaseManager
    {
        return new ReleaseManager($this->paths(), $this->fs(), $this->clock());
    }

    public function siteStatus(): SiteStatus
    {
        return new SiteStatus($this->paths(), $this->fs(), $this->sites(), $this->releases());
    }

    public function docroots(): DocrootManager
    {
        return new DocrootManager($this->paths(), $this->fs(), $this->sites());
    }

    public function docrootDetach(): DocrootDetach
    {
        return new DocrootDetach($this->paths(), $this->fs(), $this->releases(), $this->docroots());
    }

    public function siteRemover(): SiteRemover
    {
        return new SiteRemover(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->system(),
            $this->masker(),
            $this->sites(),
            $this->releases(),
            $this->docroots(),
            $this->docrootDetach(),
            $this->deployKeys(),
            $this->databases(),
            fn (): GitHubApi => $this->github(),
        );
    }

    public function changes(): ChangeAnalyzer
    {
        return new ChangeAnalyzer($this->git());
    }

    public function projects(): ProjectDetector
    {
        return new ProjectDetector();
    }

    public function composerInspector(): ComposerInspector
    {
        return new ComposerInspector($this->shell(), $this->fs(), $this->php());
    }

    public function nodeInspector(): NodeInspector
    {
        return new NodeInspector();
    }

    public function artisan(): Artisan
    {
        return new Artisan($this->shell());
    }

    public function migrations(): MigrationStatus
    {
        return new MigrationStatus($this->artisan());
    }

    public function maintenance(): Maintenance
    {
        return new Maintenance($this->artisan());
    }

    public function dbCheck(): DbCheck
    {
        return new DbCheck($this->shell());
    }

    /**
     * CPDEPLOY_HTTP_OVERRIDE=<host>:<port> (test mode only) sends health and marker
     * requests there over plain HTTP; CPDEPLOY_HTTP_NO_BACKOFF=1 skips the waits.
     */
    public function healthChecker(): HealthChecker
    {
        $noWait = $this->environment->testing('CPDEPLOY_HTTP_NO_BACKOFF') === '1'
            ? static function (int $seconds): void {
            }
        : null;

        return new HealthChecker($this->http(), $this->environment->testing('CPDEPLOY_HTTP_OVERRIDE'), $noWait);
    }

    public function preflight(): Preflight
    {
        return new Preflight($this->paths(), $this->fs(), $this->masker(), $this->docroots(), $this->composerInspector(), $this->quota(), $this->dbCheck(), $this->git(), new ReleaseManifest($this->fs()), $this->envManager());
    }

    public function deployer(): Deployer
    {
        $runner = new StepRunner($this->signals());
        $builder = new Builder(
            $runner,
            new ExportStep($this->releases(), $this->git(), $this->fs(), $this->clock()),
            new LinkSharedStep($this->paths(), $this->fs()),
            new DocrootFilesStep($this->paths(), $this->fs(), $this->docroots()),
            new ComposerStep($this->shell(), $this->fs(), $this->paths(), $this->masker()),
            new FrontendBuildStep($this->shell(), $this->fs(), $this->packageManager()),
            new StorageLinkStep($this->artisan(), $this->shell()),
            new OptimizeStep($this->artisan(), $this->shell()),
            $this->migrations(),
            $this->shell(),
            $this->fs(),
            new ReleaseManifest($this->fs()),
        );
        $goLive = new GoLive(
            $this->shell(),
            $this->fs(),
            $this->signals(),
            $this->clock(),
            $this->maintenance(),
            $this->migrations(),
            $this->multiPhp(),
            $this->docroots(),
            $this->sites(),
            $this->releases(),
            $this->php(),
            $runner,
            new SeedStep($this->artisan(), $this->shell()),
            new QueueRestartStep($this->artisan(), $this->shell()),
            $this->healthChecker(),
        );

        return new Deployer(
            $this->paths(),
            $this->fs(),
            $this->shell(),
            $this->clock(),
            $this->config(),
            $this->system(),
            $this->sites(),
            $this->domains(),
            $this->git(),
            $this->transport(),
            $this->tokens(),
            $this->releases(),
            $this->changes(),
            $this->projects(),
            $this->nodeInspector(),
            $this->php(),
            $this->composerInstaller(),
            $this->nodeResolver(),
            $this->nodeInstaller(),
            $this->migrations(),
            $this->preflight(),
            new PlanBuilder(),
            $builder,
            $goLive,
            $this->finisher(),
            $this->masker(),
            $this->recovery(),
            $this->rollbackService(),
        );
    }

    public function envManager(): EnvManager
    {
        return new EnvManager(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->masker(),
            $this->system(),
            $this->config(),
            $this->sites(),
            $this->releases(),
            $this->artisan(),
            $this->php(),
            $this->finisher(),
        );
    }

    public function laravelTools(): LaravelTools
    {
        return new LaravelTools(
            $this->paths(),
            $this->clock(),
            $this->system(),
            $this->config(),
            $this->sites(),
            $this->releases(),
            $this->php(),
            $this->artisan(),
            $this->maintenance(),
            $this->migrations(),
            $this->shell(),
        );
    }

    public function phpChange(): PhpChange
    {
        return new PhpChange(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->system(),
            $this->sites(),
            $this->releases(),
            $this->domains(),
            $this->php(),
            $this->phpLocator(),
            $this->multiPhp(),
            $this->docroots(),
            $this->composerInstaller(),
            $this->composerInspector(),
            $this->projects(),
            $this->git(),
            $this->healthChecker(),
            $this->finisher(),
        );
    }

    public function siteSettings(): SiteSettings
    {
        return new SiteSettings($this->paths(), $this->clock(), $this->system(), $this->sites(), $this->releases(), $this->finisher());
    }

    public function mirrors(): MirrorService
    {
        return new MirrorService($this->paths(), $this->sites(), $this->git(), $this->transport());
    }

    public function siteKeys(): SiteKeys
    {
        return new SiteKeys(
            $this->paths(),
            $this->clock(),
            $this->system(),
            $this->sites(),
            $this->deployKeys(),
            fn (): GitHubApi => $this->github(),
            $this->finisher(),
            $this->releases(),
        );
    }

    public function composerAuth(): ComposerAuth
    {
        return new ComposerAuth($this->paths(), $this->fs(), $this->clock(), $this->system(), $this->sites());
    }

    public function releaseActions(): ReleaseActions
    {
        return new ReleaseActions($this->paths(), $this->fs(), $this->clock(), $this->system(), $this->releases());
    }

    public function history(): History
    {
        return new History($this->paths(), $this->sites());
    }

    public function siteInfo(): SiteInfo
    {
        return new SiteInfo($this->paths(), $this->fs(), $this->sites(), $this->releases(), $this->docroots(), $this->phpChange(), $this->deployKeys());
    }

    public function databases(): DatabaseService
    {
        return new DatabaseService($this->mysql(), $this->dbCheck(), $this->masker(), $this->paths(), $this->fs());
    }

    public function repoAccess(): RepoAccess
    {
        return new RepoAccess(
            $this->paths(),
            $this->fs(),
            $this->system(),
            $this->deployKeys(),
            $this->git(),
            $this->transport(),
            fn (): GitHubApi => $this->github(),
        );
    }

    public function siteInspector(): SiteInspector
    {
        return new SiteInspector(
            $this->sites(),
            $this->presets(),
            $this->domains(),
            $this->docroots(),
            $this->projects(),
            $this->phpLocator(),
            $this->php(),
            $this->composerInstaller(),
            $this->composerInspector(),
            $this->nodeLocator(),
            $this->nodeResolver(),
            $this->nodeInstaller(),
        );
    }

    public function addFromFile(): AddFromFile
    {
        return new AddFromFile(
            $this->repoAccess(),
            $this->siteInspector(),
            $this->siteCreator(),
            $this->domains(),
            $this->sites(),
            $this->presets(),
            $this->environment,
            $this->config(),
        );
    }

    public function legacyImporter(): LegacyImporter
    {
        return new LegacyImporter($this->paths(), $this->shell());
    }

    public function siteCreator(): SiteCreator
    {
        return new SiteCreator(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->system(),
            $this->environment,
            $this->sites(),
            $this->presets(),
            $this->databases(),
            $this->php(),
            $this->finisher(),
            $this->masker(),
        );
    }

    public function editor(): Editor
    {
        return new Editor($this->shell(), $this->fs(), $this->config(), $this->environment);
    }

    public function finisher(): Finisher
    {
        return new Finisher($this->releases(), $this->paths(), $this->fs(), $this->masker(), $this->clock());
    }

    public function recovery(): Recovery
    {
        return new Recovery(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->system(),
            $this->sites(),
            $this->releases(),
            $this->php(),
            $this->domains(),
            $this->multiPhp(),
            $this->docroots(),
            $this->maintenance(),
            $this->finisher(),
            $this->masker(),
            $this->shell(),
        );
    }

    /**
     * §11.8. (Named rollbackService: Services has no state to roll back.)
     */
    public function rollbackService(): Rollback
    {
        return new Rollback(
            $this->paths(),
            $this->fs(),
            $this->clock(),
            $this->system(),
            $this->config(),
            $this->sites(),
            $this->domains(),
            $this->releases(),
            $this->php(),
            $this->phpLocator(),
            $this->multiPhp(),
            $this->docroots(),
            new LinkSharedStep($this->paths(), $this->fs()),
            new DocrootFilesStep($this->paths(), $this->fs(), $this->docroots()),
            $this->artisan(),
            $this->maintenance(),
            $this->healthChecker(),
            $this->finisher(),
            $this->recovery(),
            $this->signals(),
            $this->shell(),
            $this->masker(),
        );
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
            $this->tokenService(),
            $this->hostKeys(),
            $this->clock(),
        ));
    }

    public function hostKeyRefresh(): HostKeyRefresh
    {
        return new HostKeyRefresh($this->hostKeys(), fn (): GitHubApi => $this->github(), $this->clock());
    }

    public function selfUpdate(): SelfUpdate
    {
        return new SelfUpdate(
            $this->paths(),
            $this->fs(),
            $this->shell(),
            fn (): GitHubApi => $this->github(),
            $this->config()->updateRepo(),
            $this->environment->testing('CPDEPLOY_TEST_VERSION') ?? Version::get(),
        );
    }

    public function siteCheck(): SiteCheck
    {
        return new SiteCheck(
            $this->paths(),
            $this->fs(),
            $this->sites(),
            $this->releases(),
            $this->docroots(),
            $this->deployKeys(),
            $this->php(),
            $this->nodeResolver(),
            $this->phpChange(),
        );
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
        // Test mode only (§16.2): scripted answers make a subprocess "interactive".
        $scripted = $this->environment->testing('CPDEPLOY_TEST_ANSWERS');
        if ($scripted !== null && $input->isInteractive()) {
            $answers = json_decode($scripted, true);

            return new ScriptedAsker(is_array($answers) ? array_values($answers) : []);
        }
        if ($this->isInteractive($input)) {
            return new PromptsAsker($this->signals());
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
