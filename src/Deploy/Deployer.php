<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Git\SshCommand;
use Cpdeploy\Git\Transport;
use Cpdeploy\GitHub\TokenStore;
use Cpdeploy\Laravel\MigrationCheck;
use Cpdeploy\Laravel\MigrationStatus;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Project\ProjectDetector;
use Cpdeploy\Runtime\ComposerInstaller;
use Cpdeploy\Runtime\DomainPhp;
use Cpdeploy\Runtime\NodeInstaller;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Runtime\Shims;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Asker;
use Cpdeploy\Ui\Format;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;
use Throwable;

/**
 * Orchestrates a deploy (§11): A plan → B build → C go live → D finish.
 * The deploy command and (from M5) the deploy screen both call deploy() (ARC-03).
 */
final class Deployer
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Shell $shell,
        private readonly Clock $clock,
        private readonly GlobalConfig $config,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
        private readonly DomainService $domains,
        private readonly GitRepository $git,
        private readonly Transport $transport,
        private readonly TokenStore $tokens,
        private readonly ReleaseManager $releases,
        private readonly ChangeAnalyzer $analyzer,
        private readonly ProjectDetector $detector,
        private readonly NodeInspector $nodeInspector,
        private readonly PhpService $php,
        private readonly ComposerInstaller $composer,
        private readonly NodeResolver $nodeResolver,
        private readonly NodeInstaller $nodeInstaller,
        private readonly MigrationStatus $migrations,
        private readonly Preflight $preflight,
        private readonly PlanBuilder $planner,
        private readonly Builder $builder,
        private readonly GoLive $goLive,
        private readonly Finisher $finisher,
        private readonly Masker $masker,
    ) {
    }

    public function deploy(string $name, DeployFlags $flags, Asker $asker, Reporter $reporter): DeployResult
    {
        if (!$this->sites->exists($name)) {
            $this->sites->load($name); // throws the "no such site" error
        }
        $started = microtime(true);
        $lock = Lock::site($this->paths->siteLock($name), $name, 'deploy', $this->system->userName(), Version::get(), $this->clock);
        try {
            $state = new StateFile($this->paths->stateFile($name), $this->fs);
            if ($state->exists()) {
                $data = $state->read() ?? [];
                throw new CpdeployException(
                    ErrorCode::INTERRUPTED,
                    sprintf('An earlier %s of %s was interrupted (phase: %s)', (string) ($data['operation'] ?? 'operation'), $name, (string) ($data['phase'] ?? 'unknown')),
                    "Run: cpdeploy recover {$name}",
                    liveAffected: in_array($data['phase'] ?? '', [StateFile::MAINTENANCE, StateFile::MIGRATING, StateFile::MULTIPHP, StateFile::SWITCHING, StateFile::CONVERTING_DOCROOT], true),
                );
            }
            $log = Log::open($this->paths->logsDir($name), 'deploy', $this->masker, $this->clock, [
                'tool' => 'cpdeploy ' . Version::get(),
                'tool php' => PHP_VERSION . ' (' . PHP_BINARY . ')',
                'site' => $name,
                'user' => $this->system->userName(),
                'host' => $this->system->hostName(),
            ]);
            $this->shell->attachLog($log);
            $this->tokens->get(); // loads the token into the Masker (LOG-04)
            $ctx = null;
            try {
                $state->begin('deploy', $this->clock->iso());
                $ctx = $this->context($name, $flags, $asker, $reporter, $state, $log);
                $result = $this->phases($ctx, $started);
                $log->close($result->result, $result->exitCode);

                return $result;
            } catch (Throwable $e) {
                throw $this->failed($e, $ctx, $state, $log, $started);
            }
        } finally {
            $this->shell->attachLog(null);
            $lock->release();
        }
    }

    /**
     * PRE-02: loads and validates site.yml into a fresh run context.
     */
    private function context(string $name, DeployFlags $flags, Asker $asker, Reporter $reporter, StateFile $state, Log $log): DeployContext
    {
        $site = $this->sites->load($name);
        $ctx = new DeployContext($site, $flags->ref ?? $site->branch(), $flags, $this->paths, $this->config, $reporter, $asker);
        $ctx->log = $log;
        $ctx->state = $state;
        $ctx->deployedBy = sprintf('%s@%s (cpdeploy %s)', $this->system->userName(), $this->system->hostName(), Version::get());
        foreach ($site->warnings as $warning) {
            $ctx->warn('site.yml: ' . $warning);
        }

        return $ctx;
    }

    private function phases(DeployContext $ctx, float $started): DeployResult
    {
        // Phase A — nothing on the live site changes.
        $name = $ctx->name();
        $site = $ctx->site;
        $state = $ctx->state ?? throw new \LogicException('No state file');
        $log = $ctx->log ?? throw new \LogicException('No log');
        $reporter = $ctx->reporter;
        $this->refreshDomain($ctx);
        $this->fetch($ctx);
        $this->resolveTarget($ctx);
        $ctx->live = $this->releases->live($name);
        $ctx->changes = $this->analyzer->analyze($ctx->mirror(), $ctx->live?->commit(), $ctx->sha(), $site->webDir());
        $this->describe($ctx);

        $early = $this->sameOrRewind($ctx);
        if ($early !== null) {
            $state->delete();

            return $early;
        }

        $this->resolveRuntimes($ctx);
        $this->phpChange($ctx);
        $this->preflight->run($ctx, $ctx->domains);
        $ctx->plan = $this->planner->build($ctx, $this->livePending($ctx));
        foreach ($ctx->plan->notes() as $note) {
            $log->write('Plan: ' . $note);
        }
        $this->preflight->database($ctx); // PRE-14
        if (!$this->planner->confirm($ctx)) {
            throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — your live site was not changed', '');
        }

        // Phase B — only the new release folder changes.
        $shims = Shims::create($this->fs, $ctx->sitePhp()->binary, $ctx->composerPhar);
        $ctx->shims = $shims;
        try {
            $this->builder->build($ctx);
            // Phase C — go live.
            $live = $this->goLive->run($ctx);
        } finally {
            $shims->remove();
            $ctx->shims = null;
        }

        // Phase D — finish.
        $this->finisher->cleanup($ctx);
        $result = $live->result;
        $exit = 0;
        $message = '';
        $this->latePendingMigrations($ctx, $live, $result);
        $health = $live->health;
        if ($health !== null && !$health->ok) {
            // HC-03 (rollback on failure) arrives with M4: the new release is kept.
            $message = sprintf('%s returned %s after go-live — the new release was kept', $health->url, $health->status > 0 ? (string) $health->status : 'no response');
            if ($live->migrationsRun) {
                $message .= ' (migrations ran in this deploy; the previous code may not work with the new database)';
            }
            $result = DeployResult::WARNING;
            $exit = 7;
        }
        foreach ($ctx->plan()->notes() as $note) {
            $ctx->notes[] = $note;
        }
        $duration = microtime(true) - $started;
        $this->finisher->history(
            $name,
            'deploy',
            $result,
            $exit,
            $ctx->release?->id,
            $ctx->live?->id,
            $ctx->sha(),
            $ctx->live?->commit(),
            $duration,
            $log->path,
            $this->system->userName(),
            array_values(array_unique($ctx->notes)),
        );
        $state->delete();

        $url = 'https://' . $ctx->site->domain();
        $reporter->info(sprintf('Live in %s · %s', Format::duration($duration), $url));

        return new DeployResult($result, $exit, $ctx->release?->id, $url, $duration, $ctx->notes, $ctx->warnings, $log->path, $message);
    }

    /**
     * Step 4: refresh domain.ip from DomainInfo (HTTP-03).
     */
    private function refreshDomain(DeployContext $ctx): void
    {
        $ctx->domains = $this->domains->all();
        foreach ($ctx->domains as $domain) {
            if (strtolower($domain->name) === strtolower($ctx->site->domain())) {
                $ctx->domain = $domain;
                if ($domain->ip !== '' && $domain->ip !== $ctx->site->ip()) {
                    $ctx->site = $ctx->site->with('domain.ip', $domain->ip);
                    $this->sites->save($ctx->site);
                }
            }
        }
    }

    /**
     * Step 5: clone the mirror if needed, else fetch (GIT-07, GIT-08); one transport
     * re-detect on a connection error (GIT-04); re-clone a damaged mirror (GIT-16).
     */
    private function fetch(DeployContext $ctx): void
    {
        $site = $ctx->site;
        $repo = $site->repo();
        $mirror = $ctx->mirror();
        $ctx->reporter->start('Fetching from GitHub');
        $attempt = function (string $transport) use ($ctx, $repo, $mirror): void {
            $url = $this->transport->url($repo, $transport);
            $key = $this->paths->deployKey($ctx->name());
            if (!str_starts_with($url, 'file://') && !is_file($key)) {
                throw new CpdeployException(ErrorCode::GIT_AUTH, "The deploy key {$key} is missing", 'Rotate the key: Manage site → Deploy key → Rotate.');
            }
            $ssh = SshCommand::build($key, $this->paths->knownHosts());
            if (!is_dir($mirror)) {
                $this->git->cloneMirror($url, $mirror, $ssh, $repo->fullName());
            } else {
                $this->git->fetch($mirror, $ssh, $repo->fullName());
            }
        };

        try {
            try {
                $attempt($site->transport());
            } catch (CpdeployException $e) {
                if (Transport::worthRetrying($e)) {
                    $transport = $this->transport->detect();
                    $attempt($transport);
                    if ($transport !== $site->transport()) {
                        $ctx->site = $site->with('repo.transport', $transport);
                        $this->sites->save($ctx->site);
                        $ctx->note("git: switched to {$transport}");
                    }
                } elseif (is_dir($mirror) && GitRepository::isCorruption($e->getMessage())) {
                    $repair = $ctx->flags->yes || ($ctx->asker->interactive() && $ctx->asker->confirm('The local copy of the repository is damaged. Download it again?', true));
                    if (!$repair) {
                        throw new CpdeployException(ErrorCode::GIT, 'The local copy of the repository is damaged: ' . $e->getMessage(), 'Run the deploy again with --yes to download it again.');
                    }
                    $url = $this->transport->url($repo, $site->transport());
                    $this->git->reclone($url, $mirror, SshCommand::build($this->paths->deployKey($ctx->name()), $this->paths->knownHosts()), $repo->fullName(), $this->clock->stamp());
                    $ctx->note('git: damaged mirror downloaded again');
                } else {
                    throw $e;
                }
            }
        } catch (CpdeployException $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            throw $e;
        }
        $ctx->reporter->succeed($repo->fullName());
    }

    /**
     * Step 6 + PRE-04: resolve the ref (GIT-09), refuse submodules / LFS (GIT-14).
     */
    private function resolveTarget(DeployContext $ctx): void
    {
        $mirror = $ctx->mirror();
        $site = $ctx->site;
        $sha = $this->git->resolve($mirror, $ctx->ref, $site->repo()->fullName(), $ctx->flags->ref === null ? $site->branch() : null);
        $this->git->assertSupported($mirror, $sha);
        $ctx->commit = $this->git->commit($mirror, $sha);
        $ctx->files = new CommitFiles($this->git, $mirror, $sha);
        $ctx->info = $this->detector->detect($ctx->files);
    }

    private function describe(DeployContext $ctx): void
    {
        $changes = $ctx->changes;
        $commit = $ctx->commit;
        if ($changes === null || $commit === null) {
            return;
        }
        if ($changes->firstDeploy()) {
            $ctx->reporter->info(sprintf('First deploy of %s: %s "%s" (%s)', $ctx->name(), $commit->short, $commit->subject, $ctx->ref));

            return;
        }
        if ($changes->sameCommit) {
            return;
        }
        $ctx->reporter->info(sprintf(
            'Deploy %s: %s → %s (%d new commit%s on %s)',
            $ctx->name(),
            substr((string) $changes->liveCommit, 0, 7),
            $commit->short,
            $changes->commitCount,
            $changes->commitCount === 1 ? '' : 's',
            $ctx->ref,
        ));
        $now = $this->clock->now();
        foreach (array_slice($changes->commits, 0, 10) as $c) {
            $ctx->reporter->info(sprintf(
                '  %s  %s  %s · %s',
                $c->short,
                Format::truncate($c->subject, 40),
                $c->author,
                Format::relative((new \DateTimeImmutable())->setTimestamp($c->timestamp), $now),
            ));
        }
    }

    /**
     * PRE-05 (same commit) and PRE-06 (rewind). Returns a result to stop early.
     */
    private function sameOrRewind(DeployContext $ctx): ?DeployResult
    {
        $changes = $ctx->changes;
        $commit = $ctx->commit;
        if ($changes === null || $commit === null) {
            return null;
        }
        if ($changes->sameCommit && !$ctx->flags->force) {
            $message = sprintf('Nothing new on %s — %s is already live.', $ctx->ref, $commit->short);
            if ($ctx->asker->interactive() && !$ctx->flags->yes) {
                $choice = $ctx->asker->select($message, ['back' => 'Back', 'rebuild' => "Rebuild and redeploy {$commit->short}"], 'back');
                if ($choice === 'rebuild') {
                    return null;
                }
            }
            $ctx->reporter->info($message . ' Use --force to rebuild and redeploy it.');
            $ctx->log?->write($message);

            return new DeployResult(DeployResult::NOTHING, 0, null, null, 0.0, [], [], $ctx->log?->path, $message);
        }
        if ($changes->isRewind && !$ctx->flags->allowRewind) {
            $message = sprintf(
                '%s was rewritten: %d live commit%s not in the new history, %d new commit%s added.',
                $ctx->ref,
                $changes->behindCount,
                $changes->behindCount === 1 ? ' is' : 's are',
                $changes->commitCount,
                $changes->commitCount === 1 ? '' : 's',
            );
            if ($ctx->asker->interactive() && !$ctx->flags->yes) {
                $ctx->reporter->warn($message);
                if ($ctx->asker->select('Deploy the rewritten history?', ['deploy' => 'Deploy anyway', 'cancel' => 'Cancel'], 'cancel') === 'deploy') {
                    return null;
                }
                throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — your live site was not changed', '');
            }
            throw new CpdeployException(ErrorCode::REWIND, $message, 'Check the branch on GitHub. To deploy it anyway: --allow-rewind');
        }

        return null;
    }

    /**
     * Step 8: site PHP (PHP-04), Composer (CMP-01), Node (NODE-01…06), with progress.
     */
    private function resolveRuntimes(DeployContext $ctx): void
    {
        $site = $ctx->site;
        $info = $ctx->info();
        $ctx->php = $this->php->resolve($site->phpVersion(), $site->phpFamily());
        if ($ctx->php->majorMinor() !== $site->phpVersion()) {
            throw new CpdeployException(ErrorCode::PHP_MISSING, "PHP {$site->phpVersion()} isn't installed on this server", 'Choose another version (Manage site → PHP version).');
        }

        if ($info->hasComposer()) {
            $ctx->composerPhar = $this->composer->ensure($site->composerVersion(), $ctx->reporter, 3);
            $version = $this->shell->run([$ctx->php->binary, $ctx->composerPhar, '--version', '--no-ansi'], new RunOptions(timeout: 60, label: 'composer --version'));
            $ctx->composerVersion = preg_match('/Composer (?:version )?(\d+\.\d+\.\d+\S*)/', $version->stdout, $m) === 1 ? $m[1] : $site->composerVersion();
        }

        if (NodeInspector::builds($site, $info) && !($ctx->flags->skipBuild && $ctx->live !== null)) {
            $spec = $this->nodeInspector->spec($site, $ctx->files ?? throw new \LogicException('No files'));
            $node = $this->nodeResolver->installed($spec);
            if ($node === null && $spec !== null) {
                $version = $this->nodeResolver->fromIndex($spec, $this->nodeInstaller->arch());
                if ($version === null) {
                    throw new CpdeployException(ErrorCode::NODE_NONE, 'No Node.js release matches ' . $spec->describe(), 'Set a Node version that exists (Manage site → Node version), or fix ' . $spec->source . '.');
                }
                $node = $this->nodeInstaller->install($version, $ctx->reporter, 3);
            }
            $ctx->node = $node;
            $ctx->packageManager = $this->nodeInspector->packageManager($site, $ctx->files);
        }
    }

    /**
     * GL-03 input and PRE-20: how the domain's MultiPHP version changes. Only with
     * php.sync_multiphp, and never for a domain on CloudLinux PHP Selector that
     * builds with alt-php (PHP-06 option b).
     */
    private function phpChange(DeployContext $ctx): void
    {
        $site = $ctx->site;
        if (!$site->syncMultiPhp() || $ctx->domain === null) {
            return;
        }
        try {
            $current = $this->php->domainPhp($ctx->domain);
        } catch (Throwable $e) {
            $ctx->warn("Couldn't read the domain's PHP version: " . $e->getMessage());

            return;
        }
        if ($current === null) {
            return;
        }
        $ctx->domainPhpTag = $current->tag();
        if ($site->phpFamily() === PhpInstall::ALT && $current->source === DomainPhp::SOURCE_SELECTOR) {
            return;
        }
        $ctx->phpChange = PhpService::phpChange($current->tag(), $ctx->sitePhp()->tag());
    }

    /**
     * PLN-03: migrations still pending in the live release (best effort).
     */
    private function livePending(DeployContext $ctx): ?MigrationCheck
    {
        $live = $ctx->live;
        if ($live === null || !$ctx->site->isLaravel() || $ctx->site->step('migrate') === 'off' || !is_file($live->dir . '/artisan')) {
            return null;
        }
        try {
            return $this->migrations->pending(
                $live->dir,
                $this->php->forRelease($live->phpBinary(), $ctx->sitePhp()),
                $ctx->info()->laravelMajor(),
                new RunOptions(timeout: $ctx->timeout('artisan')),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * PLN-07: migrations found by B9 although no question was shown (e.g. from
     * packages) are not run in Phase C. Interactive: offer to run them now.
     */
    private function latePendingMigrations(DeployContext $ctx, GoLiveResult $live, string &$result): void
    {
        $check = $ctx->pendingAfterBuild;
        if ($ctx->plan()->migrate !== DeployPlan::MIGRATE_NONE || $check === null || !$check->known || $check->pending === []) {
            return;
        }
        $count = count($check->pending);
        $text = sprintf('%d package migration%s pending (not in your repo\'s database/migrations): %s', $count, $count === 1 ? ' is' : 's are', implode(', ', $check->pending));
        if ($ctx->asker->interactive() && !$ctx->flags->yes) {
            $ctx->reporter->warn($text);
            if ($ctx->asker->select('Run them now?', ['run' => 'Run it now (maintenance on for a few seconds)', 'later' => 'Not now'], 'run') === 'run') {
                $this->runLateMigrations($ctx);

                return;
            }
        }
        $ctx->warn($text . ' — run: cpdeploy artisan ' . $ctx->name() . ' -- migrate --force');
        if ($result === DeployResult::SUCCESS) {
            $result = DeployResult::WARNING;
        }
    }

    private function runLateMigrations(DeployContext $ctx): void
    {
        $release = $ctx->release ?? throw new \LogicException('No release');
        $php = $ctx->sitePhp()->binary;
        $current = $this->paths->current($ctx->name());
        $ctx->reporter->start('Migrations');
        $options = $ctx->options($ctx->timeout('migrate'), 'artisan migrate', cwd: $current);
        $this->shell->run([$php, 'artisan', 'down', '--retry=60', '--no-interaction'], $options);
        $result = $this->shell->run([$php, 'artisan', 'migrate', '--force', '--no-interaction', '--ansi'], $options);
        $this->shell->run([$php, 'artisan', 'up', '--no-interaction'], $options);
        if (!$result->successful()) {
            $ctx->reporter->fail('php artisan migrate failed', $ctx->log?->path);
            $ctx->warn('The package migrations failed; the site is up. See the log.');

            return;
        }
        $ctx->reporter->succeed('');
        $ctx->note('migrations: package migrations ran after go-live');
        $release->set('migrations', ['ran' => true, 'list' => $ctx->pendingAfterBuild->pending ?? []]);
        $release->save($this->fs);
    }

    /**
     * Failure handling for every phase: the release is marked failed when it was
     * still building (BLD-03 / §11.4), history gets an entry, the state file goes
     * (a handled failure, LCK-03) and the error carries the log path.
     */
    private function failed(Throwable $e, ?DeployContext $ctx, StateFile $state, Log $log, float $started): CpdeployException
    {
        $error = $e instanceof CpdeployException
            ? $e
            : new CpdeployException(ErrorCode::INTERNAL, 'Unexpected error: ' . $e->getMessage(), 'This is a bug in cpdeploy. Please report it with the log.', previous: $e);
        $log->write('ERROR ' . $error->errorCode->value . ': ' . $error->getMessage());
        if (!$e instanceof CpdeployException) {
            $log->write((string) $e);
        }

        $release = $ctx?->release;
        if ($ctx !== null && $release !== null && in_array($release->status(), [Release::BUILDING, Release::UNKNOWN], true)) {
            $release->set('status', Release::FAILED);
            $release->set('durations', $ctx->durations);
            try {
                $release->save($this->fs);
            } catch (Throwable) {
                // The folder is removed by the next cleanup anyway.
            }
        }
        $ctx?->shims?->remove();

        $result = $error->errorCode === ErrorCode::CANCELLED ? 'cancelled' : 'failed';
        if ($ctx !== null) {
            $this->finisher->history(
                $ctx->name(),
                'deploy',
                $result,
                $error->exitCode(),
                $release?->id,
                $ctx->live?->id,
                $ctx->commit?->sha,
                $ctx->live?->commit(),
                microtime(true) - $started,
                $log->path,
                $this->system->userName(),
                array_values(array_unique([...$ctx->notes, $error->errorCode->value . ': ' . strtok($error->getMessage(), "\n")])),
            );
        }
        $state->delete();
        $log->close($result, $error->exitCode());

        return $error->withLogPath($log->path);
    }
}
