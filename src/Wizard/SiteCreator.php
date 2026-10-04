<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\Presets;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Database\DatabaseService;
use Cpdeploy\Deploy\Finisher;
use Cpdeploy\Env\EnvCreator;
use Cpdeploy\Env\EnvFile;
use Cpdeploy\Laravel\AppKey;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use RuntimeException;
use Throwable;

/**
 * WIZ-04: Create as a transaction. The site folder (with the temporary mirror
 * moved in), the shared skeleton, the database and user, shared/.env (and an
 * imported storage/), the connection test, site.yml, and a `site-create`
 * history entry. A failure undoes the earlier steps in reverse; the deploy key
 * stays (the caller offers to remove it). The wizard and `add --from` both use
 * it (ARC-03).
 */
final class SiteCreator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly Environment $environment,
        private readonly SiteRegistry $sites,
        private readonly Presets $presets,
        private readonly DatabaseService $database,
        private readonly PhpService $php,
        private readonly Finisher $finisher,
        private readonly Masker $masker,
    ) {
    }

    public function create(WizardState $state, WizardTransaction $tx, Reporter $reporter): SiteConfig
    {
        $name = $state->name;
        $createdAt = $this->clock->iso();
        $config = $state->config($this->presets->for($state->type ?? 'laravel'), $createdAt);
        if ($this->sites->exists($name) || is_dir($this->paths->siteDir($name))) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "A site named {$name} already exists", 'Choose another site name.');
        }
        $filesDir = self::checkSiteDir($this->paths, $config->siteDir());
        $notes = [];
        try {
            // 1. The site folder, and the mirror moved in.
            $reporter->start('Create the site folder');
            $siteDir = $this->paths->siteDir($name);
            $this->fs->ensureDir($this->paths->sitesDir(), Paths::MODE_ROOT);
            $this->fs->ensureDir($siteDir, Paths::MODE_ROOT);
            $tx->siteDir = $siteDir;
            if ($state->mirror !== null && is_dir($state->mirror)) {
                if (!@rename($state->mirror, $this->paths->mirror($name))) {
                    throw new RuntimeException("Couldn't move the repository copy into {$siteDir}");
                }
                @chmod($this->paths->mirror($name), Paths::MODE_PRIVATE_DIR);
                $state->mirror = $this->paths->mirror($name);
            }
            $reporter->succeed($siteDir);

            // 2. The site's own folder (LAY-04) and the shared skeleton (REL-03 fills it at the first deploy).
            $this->paths->useSiteDir($name, $config->siteDir());
            $this->fs->ensureDir(dirname($filesDir), Paths::MODE_ROOT);
            $this->fs->ensureDir($filesDir, Paths::MODE_ROOT);
            $tx->siteFilesDir = $filesDir;
            $this->fs->ensureDir($this->paths->sharedDir($name), Paths::MODE_ROOT);
            $this->failIf('shared');

            // 3. The database and user (DB-01).
            $dbEnv = $this->database($state, $tx, $reporter, $notes);
            $config = $state->config($this->presets->for($state->type ?? 'laravel'), $createdAt); // with the database names

            // 4. shared/.env, and an imported storage/.
            $envText = $this->env($state, $dbEnv);
            if ($envText !== null && in_array('.env', $config->sharedFiles(), true)) {
                $this->fs->writeAtomic($this->paths->sharedEnv($name), $envText, Paths::MODE_SECRET_FILE);
                $notes[] = '.env: ' . match ($state->envMode) {
                    WizardState::ENV_IMPORT => 'imported from ' . $state->importFrom,
                    WizardState::ENV_PASTE => 'pasted',
                    WizardState::ENV_FILE => 'from ' . $state->envContent,
                    default => 'from .env.example',
                };
            }
            if ($state->importFrom !== null && is_dir($state->importFrom . '/storage')) {
                $reporter->start('Copy storage/ from ' . $state->importFrom);
                $this->fs->ensureDir($this->paths->sharedDir($name) . '/storage', Paths::MODE_PUBLIC_DIR);
                $this->fs->copyTree($state->importFrom . '/storage/.', $this->paths->sharedDir($name) . '/storage');
                $reporter->succeed('');
                $notes[] = 'storage/ copied from ' . $state->importFrom;
            }
            $this->failIf('env');

            // 5. Connection test: a warning only.
            if ($envText !== null && $config->isLaravel() && $state->dbMode !== WizardState::DB_NONE) {
                $this->testDatabase($config, $envText, $reporter);
            }

            // 6. site.yml.
            $this->failIf('site.yml');
            $this->sites->save($config);

            // 7. History.
            $this->finisher->history($name, 'site-create', 'success', 0, null, null, null, null, 0.0, null, $this->system->userName(), [
                "repo {$config->repo()->fullName()} ({$config->branch()})",
                "domain {$config->domain()} → {$config->docroot()}",
                ...$notes,
            ]);

            return $config;
        } catch (Throwable $e) {
            $this->undo($state, $tx, $reporter);
            if ($e instanceof CpdeployException) {
                throw $e;
            }
            throw new CpdeployException(ErrorCode::INTERNAL, "Couldn't create the site: " . $e->getMessage(), 'Nothing was kept except the deploy key. See the message above.', previous: $e);
        }
    }

    /**
     * LAY-04: the new site's folder, ~/<site_dir>, must not exist yet. Returns its
     * absolute path. (That it isn't inside a domain's folder is DOC-01's check,
     * at the wizard's domain step and before every go-live.)
     */
    public static function checkSiteDir(Paths $paths, string $siteDir): string
    {
        if ($siteDir === '') {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "The site's folder is unknown (no domain chosen)", 'Choose the domain first.');
        }
        $filesDir = $paths->fromHome($siteDir);
        if (file_exists($filesDir) || is_link($filesDir)) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                "The folder ~/{$siteDir} already exists",
                'Each site gets a new folder named after its domain. Move or delete that folder first (it may be left from an earlier site).',
            );
        }

        return $filesDir;
    }

    /**
     * WIZ-04 undo, in reverse: the database and user this run created, the
     * site's own folder, the site folder. The temporary mirror went into the
     * site folder, so it goes too.
     */
    public function undo(WizardState $state, WizardTransaction $tx, Reporter $reporter): void
    {
        if ($tx->database !== null || $tx->user !== null) {
            try {
                $this->database->drop($tx->database, $tx->user);
                $reporter->info('Removed the database and user created for this site');
            } catch (Throwable $e) {
                $reporter->warn(sprintf("Couldn't remove the database %s / user %s: %s — remove them in cPanel → MySQL Databases", (string) $tx->database, (string) $tx->user, $e->getMessage()));
            }
            $tx->database = null;
            $tx->user = null;
        }
        if ($tx->siteFilesDir !== null) {
            try {
                $this->fs->deleteSiteFiles($state->name);
            } catch (Throwable $e) {
                $reporter->warn("Couldn't remove {$tx->siteFilesDir}: " . $e->getMessage());
            }
            $tx->siteFilesDir = null;
        }
        if ($tx->siteDir !== null) {
            try {
                $this->fs->deleteSiteFolder($state->name);
            } catch (Throwable $e) {
                $reporter->warn("Couldn't remove {$tx->siteDir}: " . $e->getMessage());
            }
            $tx->siteDir = null;
            $state->mirror = null;
        }
    }

    /**
     * DB-01 (create, recorded for undo), DB-02 (existing) or DB-03 (SQLite).
     *
     * @param list<string> $notes
     * @return array<string, string> the DB_* values for .env
     */
    private function database(WizardState $state, WizardTransaction $tx, Reporter $reporter, array &$notes): array
    {
        switch ($state->dbMode) {
            case WizardState::DB_CREATE:
                [$database, $user] = $state->dbName !== null && $state->dbUser !== null ? [$state->dbName, $state->dbUser] : $this->database->names($state->name);
                $reporter->start('Create database');
                $this->database->createDatabase($database);
                $tx->database = $database;
                $this->failIf('database');
                $password = $this->database->createUser($user);
                $tx->user = $user;
                $this->database->grant($user, $database);
                $state->dbName = $database;
                $state->dbUser = $user;
                $reporter->succeed("{$database} · user {$user}");
                $notes[] = "database {$database} and user {$user} created";

                return DatabaseService::mysqlEnv($database, $user, $password);
            case WizardState::DB_EXISTING:
                $this->masker->add($state->dbPassword);
                $notes[] = "database {$state->dbName} (existing)";

                return DatabaseService::mysqlEnv((string) $state->dbName, (string) $state->dbUser, (string) $state->dbPassword);
            case WizardState::DB_SQLITE:
                $file = $this->database->sqlite($state->name);
                $notes[] = 'database: SQLite';

                return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file];
            default:
                return [];
        }
    }

    /**
     * ENV-09 and the other .env sources. Null = no .env yet (*I'll add it later*).
     *
     * @param array<string, string> $db
     */
    private function env(WizardState $state, array $db): ?string
    {
        switch ($state->envMode) {
            case WizardState::ENV_LATER:
                return null;
            case WizardState::ENV_IMPORT:
                $text = (string) file_get_contents((string) $state->importFrom . '/.env');

                return $db === [] ? $text : EnvCreator::applyDatabase($text, $db);
            case WizardState::ENV_PASTE:
            case WizardState::ENV_FILE:
                $text = $state->envMode === WizardState::ENV_FILE ? (string) @file_get_contents((string) $state->envContent) : (string) $state->envContent;
                $env = EnvFile::parse(EnvCreator::applyDatabase($text, $db));
                if (!AppKey::isSet($env->get('APP_KEY')) && ($state->type ?? 'laravel') === 'laravel') {
                    $env = $env->set('APP_KEY', AppKey::generate());
                }

                return $env->toString();
            default:
                $example = $state->files?->read('.env.example');

                return EnvCreator::create(
                    $example,
                    $state->appName !== '' ? $state->appName : $state->name,
                    $state->appUrl !== '' ? $state->appUrl : 'https://' . $state->domain,
                    AppKey::generate(),
                    $db,
                );
        }
    }

    private function testDatabase(SiteConfig $config, string $envText, Reporter $reporter): void
    {
        try {
            $env = EnvFile::parse($envText)->all();
            $this->masker->addEnv($env);
            $php = $this->php->resolve($config->phpVersion(), $config->phpFamily());
            $reporter->start('Database connection');
            $result = $this->database->test($php->binary, $env, $this->paths->sharedDir($config->name()));
            if ($result->ok()) {
                $reporter->succeed('connected');
            } else {
                $reporter->succeed('');
                $reporter->warn("Couldn't connect to the database yet: {$result->detail}. Check DB_* in .env before the first deploy.");
            }
        } catch (Throwable $e) {
            $reporter->warn("Couldn't test the database connection: " . $e->getMessage());
        }
    }

    /**
     * Test seam (§16.2): CPDEPLOY_TEST_FAIL_CREATE=<step> fails Create at that step (S-31).
     */
    private function failIf(string $step): void
    {
        if ($this->environment->testing('CPDEPLOY_TEST_FAIL_CREATE') === $step) {
            throw new RuntimeException("Simulated failure at {$step} (CPDEPLOY_TEST_FAIL_CREATE)");
        }
    }
}
