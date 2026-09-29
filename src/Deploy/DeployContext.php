<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Git\Commit;
use Cpdeploy\Laravel\MigrationCheck;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Runtime\NodeVersion;
use Cpdeploy\Runtime\PackageManagerChoice;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\Shims;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\Reporter;

/**
 * Everything one deploy run needs (§6.3): the site, the target commit, the live
 * and new releases, the runtimes, the plan, and where to report. Filled in phase
 * by phase; the steps read it.
 */
final class DeployContext
{
    public ?Domain $domain = null;

    /** @var list<Domain> every domain of the account (DOC-01 d/g re-check at G6) */
    public array $domains = [];
    public ?Commit $commit = null;
    public ?CommitFiles $files = null;
    public ?ProjectInfo $info = null;
    public ?ChangeSet $changes = null;
    public ?Release $live = null;
    public ?Release $release = null;
    public ?PhpInstall $php = null;
    public ?string $composerPhar = null;
    public ?string $composerVersion = null;
    public ?NodeVersion $node = null;
    public ?PackageManagerChoice $packageManager = null;
    public ?Shims $shims = null;
    public ?DeployPlan $plan = null;
    public ?MigrationCheck $pendingAfterBuild = null;
    public ?Log $log = null;
    public ?StateFile $state = null;

    /** "user@host (cpdeploy x.y.z)" for .release.json → deployed_by. */
    public string $deployedBy = '';

    /** The domain's current PHP tag (e.g. ea-php82), when known. */
    public ?string $domainPhpTag = null;
    /** GL-03 input: none | upgrade | downgrade | family. */
    public string $phpChange = 'none';

    /** @var array<string, string> the site's .env values (for the Masker and checks) */
    public array $env = [];

    /** @var array<string, int> step durations in seconds (.release.json → durations) */
    public array $durations = [];

    /** @var list<string> history notes (§8.6) */
    public array $notes = [];

    /** @var list<string> warnings shown on the result screen */
    public array $warnings = [];

    /** @var list<string> command output (package migrations pending after go-live, PLN-07) */
    public array $latePending = [];

    public function __construct(
        public SiteConfig $site,
        public readonly string $ref,
        public readonly DeployFlags $flags,
        public readonly Paths $paths,
        public readonly GlobalConfig $config,
        public readonly Reporter $reporter,
        public readonly Asker $asker,
    ) {
    }

    public function name(): string
    {
        return $this->site->name();
    }

    public function mirror(): string
    {
        return $this->paths->mirror($this->name());
    }

    public function releaseDir(): string
    {
        return $this->release !== null ? $this->release->dir : '';
    }

    public function firstDeploy(): bool
    {
        return $this->live === null;
    }

    public function sha(): string
    {
        return $this->commit !== null ? $this->commit->sha : '';
    }

    public function sitePhp(): PhpInstall
    {
        if ($this->php === null) {
            throw new \LogicException('Site PHP not resolved yet');
        }

        return $this->php;
    }

    public function info(): ProjectInfo
    {
        if ($this->info === null) {
            throw new \LogicException('Project not inspected yet');
        }

        return $this->info;
    }

    public function plan(): DeployPlan
    {
        if ($this->plan === null) {
            throw new \LogicException('No deploy plan yet');
        }

        return $this->plan;
    }

    public function timeout(string $key): float
    {
        return (float) $this->config->timeout($key);
    }

    /**
     * PATH additions for commands in the release: the shims first (BLD-01), then Node (NODE-10).
     *
     * @return list<string>
     */
    public function pathPrefix(bool $withNode = false): array
    {
        $prefix = [];
        if ($this->shims !== null) {
            $prefix[] = $this->shims->dir;
        }
        if ($withNode && $this->node !== null) {
            $prefix[] = $this->node->binDir;
        }

        return $prefix;
    }

    /**
     * Run options for a step: working directory = the new release, shims (and Node)
     * on PATH, output streamed to the reporter and the log.
     *
     * @param array<string, string> $env
     */
    public function options(float $timeout, string $label, array $env = [], bool $withNode = false, ?string $cwd = null): RunOptions
    {
        return (new RunOptions(
            cwd: $cwd ?? $this->releaseDir(),
            env: $env,
            timeout: $timeout,
            pathPrefix: $this->pathPrefix($withNode),
            label: $label,
        ))->reporting(
            fn (string $line) => $this->reporter->line($line),
            fn () => $this->reporter->tick(),
        );
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
        $this->reporter->warn($text);
        $this->log?->write('WARNING: ' . $text);
    }

    public function note(string $text): void
    {
        $this->notes[] = $text;
        $this->log?->write('NOTE: ' . $text);
    }
}
