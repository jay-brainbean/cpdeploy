<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Cpanel\QuotaService;
use Cpdeploy\Database\DbCheck;
use Cpdeploy\Database\DbCheckResult;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Env\EnvFile;
use Cpdeploy\Laravel\AppKey;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;
use Cpdeploy\Ui\Format;
use Throwable;

/**
 * Preflight checks (§11.2): nothing has changed yet. Blocking problems are all
 * listed together and stop the deploy with exit 3; warnings are shown and the
 * deploy continues. PRE-01…06 run earlier in the Deployer, PRE-14 (database)
 * after the questions, PRE-17/18 arrive with M4.
 */
final class Preflight
{
    /** PRE-15: free space ≥ 1.3 × the estimate; inodes ≥ 60 000 when Node builds. */
    public const DISK_FACTOR = 1.3;
    public const MIN_INODES = 60000;
    public const FIRST_DEPLOY_EXTRA_MB = 400;

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Masker $masker,
        private readonly DocrootManager $docroots,
        private readonly ComposerInspector $composer,
        private readonly QuotaService $quota,
        private readonly DbCheck $db,
    ) {
    }

    /**
     * @param list<Domain> $domains
     */
    public function run(DeployContext $ctx, array $domains): void
    {
        /** @var list<CpdeployException> $blocks */
        $blocks = [];
        $ok = [];
        $site = $ctx->site;
        $info = $ctx->info();
        $php = $ctx->sitePhp();
        $files = $ctx->files ?? throw new \LogicException('No commit files');

        // PRE-07
        $ok[] = 'PHP ' . $php->majorMinor();

        // PRE-08 (PHP-05)
        if ($info->hasComposer() && $ctx->composerPhar !== null) {
            $problems = $this->composer->platformProblems($info, $files, $php, $ctx->composerPhar);
            if ($problems !== []) {
                $blocks[] = new CpdeployException(
                    ErrorCode::PLATFORM,
                    sprintf('PHP %s is missing what composer.lock needs: %s', $php->majorMinor(), implode(', ', $problems)),
                    'Enable the extensions in cPanel → MultiPHP INI Editor / Select PHP Version, or choose another PHP (Manage site → PHP version).',
                );
            }
        }

        // PRE-09
        if ($info->hasComposer() && !$info->hasLock()) {
            if ($site->allowNoLock()) {
                $ctx->warn('composer.lock is missing: dependencies will be resolved fresh');
            } else {
                $blocks[] = new CpdeployException(ErrorCode::NO_LOCK, 'composer.lock is missing', "Commit composer.lock, or set composer.allow_no_lock: true (cpdeploy config {$site->name()} set composer.allow_no_lock true).");
            }
        }

        // PRE-10
        if ($ctx->node !== null) {
            $ok[] = 'Node ' . $ctx->node->major();
            if ($ctx->packageManager !== null && !(new NodeInspector())->hasLockfile($ctx->packageManager, $files)) {
                $ctx->warn(sprintf('No %s in the repo: dependencies are resolved fresh on every build', NodeInspector::LOCKFILES[$ctx->packageManager->name] ?? 'lockfile'));
            }
        }

        // SEC-11: a .env inside the served folder would be downloadable.
        $webEnv = ($site->webDir() === '' ? '' : $site->webDir() . '/') . '.env';
        if ($files->exists($webEnv)) {
            $blocks[] = new CpdeployException(ErrorCode::DOCROOT_UNSAFE, "The repo contains {$webEnv}: it would be downloadable from the website", "Remove {$webEnv} from the repo (and change those secrets).");
        }

        // PRE-11, PRE-12, PRE-13
        if (in_array('.env', $site->sharedFiles(), true)) {
            $envFile = $this->paths->sharedEnv($site->name());
            if (!is_file($envFile)) {
                $blocks[] = new CpdeployException(ErrorCode::ENV_MISSING, '.env is missing (' . $envFile . ')', 'Create it: Manage site → Environment, or copy your .env to ' . $envFile . ' (mode 600).');
            } else {
                if ((fileperms($envFile) & 0777) !== Paths::MODE_SECRET_FILE) {
                    @chmod($envFile, Paths::MODE_SECRET_FILE);
                    $ctx->note('.env mode set to 600');
                }
                try {
                    $env = EnvFile::fromFile($envFile);
                    $ctx->env = $env->all();
                    $this->masker->addEnv($ctx->env);
                    foreach ($env->warnings as $warning) {
                        $ctx->warn('.env: ' . $warning);
                    }
                    $ok[] = '.env';
                    if ($site->isLaravel()) {
                        if (!AppKey::isSet($env->get('APP_KEY'))) {
                            $blocks[] = new CpdeployException(ErrorCode::APP_KEY, 'APP_KEY is empty in .env', 'Generate one: Manage site → Environment → Generate APP_KEY.');
                        }
                        if (strtolower((string) $env->get('APP_ENV')) === 'local' || strtolower((string) $env->get('APP_DEBUG')) === 'true') {
                            $ctx->warn('.env has APP_ENV=local or APP_DEBUG=true — not safe for a live site');
                        }
                    }
                } catch (CpdeployException $e) {
                    $blocks[] = $e;
                }
            }
        }

        // PRE-15
        $disk = $this->disk($ctx, $blocks);
        if ($disk !== null) {
            array_unshift($ok, $disk);
        }

        // PRE-16 / PRE-23 (DOC-01 a–g)
        foreach ($this->docroots->problems($site, $domains) as $problem) {
            $blocks[] = new CpdeployException(ErrorCode::DOCROOT_UNSAFE, $problem, 'Fix it in cPanel → Domains (or with cpdeploy config), then deploy again.');
        }

        // PRE-19
        if ($ctx->live !== null && Maintenance::isDown($ctx->live->dir)) {
            $ctx->warn('The site is in maintenance mode. The new release will be up after go-live; turn maintenance on again afterwards if you need it.');
        }

        // PRE-20
        if ($ctx->phpChange !== PhpService::CHANGE_NONE && $ctx->domainPhpTag !== null) {
            $ctx->reporter->info(sprintf('Domain will switch from %s to %s at go-live', $ctx->domainPhpTag, $php->tag()));
        }

        // PRE-21: keep a PHP handler block changed in cPanel's MultiPHP Manager (DOC-04).
        if ($blocks === []) {
            $served = $this->docroots->servedFolder($site);
            if (is_dir($served)) {
                $this->docroots->captureHandler($site, $served);
                if ($ctx->firstDeploy() && !$this->docroots->isConverted($site) && !is_link($site->docroot())) {
                    // Before the first go-live: keep .well-known / .user.ini so the new release links them.
                    $this->docroots->copyExtras($site, $site->docroot());
                }
            }
        }

        // PRE-22
        if ($site->deployKeyId() !== null && !is_file($this->paths->tokenFile())) {
            $ctx->warn('The deploy key was added with a GitHub token that is no longer set: key removal/rotation will need a new token');
        }

        $ctx->reporter->info('Checks OK: ' . implode(' · ', $ok));

        if ($blocks !== []) {
            throw self::combine($blocks);
        }
    }

    /**
     * PRE-14 (DB-04), once the answers are known: blocks when migrations will or may
     * run, otherwise only warns. Checked when migrations may run or on a first deploy.
     */
    public function database(DeployContext $ctx): ?DbCheckResult
    {
        $plan = $ctx->plan();
        if (!$ctx->site->isLaravel() || $ctx->site->step('migrate') === 'off' || (!$plan->mayMigrate() && !$ctx->firstDeploy())) {
            return null;
        }
        $env = $ctx->env;
        $major = $ctx->info()->laravelMajor();
        $driver = strtolower($env['DB_CONNECTION'] ?? ($major !== null && $major >= 11 ? 'sqlite' : 'mysql'));
        $database = $env['DB_DATABASE'] ?? '';
        if ($driver === 'sqlite' && ($database === '' || !str_starts_with($database, '/'))) {
            $ctx->warn(sprintf(
                'The SQLite database is inside the release and would be replaced by every deploy. Set DB_DATABASE to an absolute path, e.g. %s/database/database.sqlite',
                $this->paths->sharedDir($ctx->name()),
            ));
            if ($ctx->live === null) {
                return null;
            }
        }
        $result = $this->db->check($ctx->sitePhp()->binary, $env, $ctx->live->dir ?? $this->paths->sharedDir($ctx->name()), $major !== null && $major >= 11 ? 'sqlite' : 'mysql');
        if ($result->ok()) {
            $ctx->reporter->info('Database: connected');

            return $result;
        }
        if ($result->status === DbCheck::SKIPPED) {
            return $result;
        }
        $exception = $result->status === DbCheck::NO_DRIVER
            ? new CpdeployException(ErrorCode::DB_DRIVER, sprintf('PHP %s lacks %s', $ctx->sitePhp()->majorMinor(), $result->detail), 'Enable the extension in cPanel → MultiPHP INI Editor / Select PHP Version.')
            : new CpdeployException(ErrorCode::DB_CONNECT, "Can't connect to the database: {$result->detail}", 'Check DB_* in .env (Manage site → Environment).');
        if ($plan->mayMigrate()) {
            throw $exception;
        }
        $ctx->warn($exception->getMessage());

        return $result;
    }

    /**
     * PRE-15. Returns "disk 4.2 GB free", or null when the quota is unlimited or unknown.
     *
     * @param list<CpdeployException> $blocks
     */
    private function disk(DeployContext $ctx, array &$blocks): ?string
    {
        try {
            $quota = $this->quota->quota();
        } catch (Throwable $e) {
            $ctx->warn("Couldn't read the disk quota: " . $e->getMessage());

            return null;
        }
        $freeMb = $quota->megabytesFree();
        if ($freeMb !== null) {
            $estimateKb = $ctx->live !== null
                ? $this->fs->diskUsageKb($ctx->live->dir)
                : (int) (($this->fs->diskUsageKb($ctx->mirror()) ?? 0) * 1.2) + self::FIRST_DEPLOY_EXTRA_MB * 1024;
            $needMb = (int) ceil(($estimateKb ?? 0) / 1024 * self::DISK_FACTOR);
            if ($freeMb < $needMb) {
                $blocks[] = new CpdeployException(
                    ErrorCode::DISK,
                    sprintf('Not enough disk space (need ≈%d MB, %d MB free)', $needMb, (int) $freeMb),
                    'Free space, lower releases.keep, or delete old releases (cpdeploy releases ' . $ctx->name() . ').',
                );
            }
        }
        $inodes = $quota->inodesFree();
        if ($inodes !== null && $ctx->plan === null && $ctx->node !== null && $inodes < self::MIN_INODES) {
            $blocks[] = new CpdeployException(
                ErrorCode::INODES,
                sprintf('Not enough inodes for a Node build (%d free, %d needed)', $inodes, self::MIN_INODES),
                'Delete old releases or unused files, or ask your host to raise the inode limit.',
            );
        }

        return $freeMb === null ? null : 'disk ' . Format::bytes($freeMb * 1024 * 1024) . ' free';
    }

    /**
     * @param non-empty-list<CpdeployException> $blocks
     */
    public static function combine(array $blocks): CpdeployException
    {
        if (count($blocks) === 1) {
            return $blocks[0];
        }
        $lines = array_map(static fn (CpdeployException $e): string => $e->getMessage() . ($e->hint !== '' ? "\n      Fix: " . $e->hint : ''), $blocks);

        return new CpdeployException(
            $blocks[0]->errorCode,
            count($blocks) . " checks failed:\n  - " . implode("\n  - ", $lines),
            'Fix the problems above, then deploy again.',
            exitCode: 3,
        );
    }
}
