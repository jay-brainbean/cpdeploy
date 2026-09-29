<?php

declare(strict_types=1);

namespace Cpdeploy\Laravel;

use Closure;
use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Deploy\Release;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Reporter;
use Cpdeploy\Version;

/**
 * Manage site → Laravel tools (§9.5.8) and the `artisan`, `down` and `up`
 * commands. Everything runs in the **live** release with that release's PHP
 * (PHP-07); anything that can change state takes the site lock (UIG-06).
 */
final class LaravelTools
{
    /** LAR-08: commands that need the site name typed before they run. */
    public const DANGEROUS = ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'migrate:rollback', 'db:wipe', 'db:seed', 'key:generate'];

    /** Lines of laravel.log shown. */
    public const LOG_LINES = 100;

    public function __construct(
        private readonly Paths $paths,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly GlobalConfig $config,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly PhpService $php,
        private readonly Artisan $artisan,
        private readonly Maintenance $maintenance,
        private readonly MigrationStatus $migrations,
        private readonly Shell $shell,
    ) {
    }

    /**
     * LAR-08.
     *
     * @param list<string> $args
     */
    public static function isDangerous(array $args): bool
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                return in_array(strtolower($arg), self::DANGEROUS, true);
            }
        }

        return false;
    }

    /**
     * The site (Laravel only), its live release and that release's PHP.
     *
     * @return array{0: SiteConfig, 1: Release, 2: string}
     */
    public function live(string $site): array
    {
        $config = $this->sites->load($site);
        if (!$config->isLaravel()) {
            throw new CpdeployException(ErrorCode::USAGE, "{$site} isn't a Laravel site", 'Laravel tools only work for sites with type: laravel.');
        }
        $live = $this->releases->live($site);
        if ($live === null || !is_file($live->dir . '/artisan')) {
            throw new CpdeployException(ErrorCode::USAGE, "{$site} has no live release yet", "Deploy it first: cpdeploy deploy {$site}");
        }

        return [$config, $live, $this->php->forRelease($live->phpBinary(), $this->php->resolve($config->phpVersion(), $config->phpFamily()))];
    }

    /**
     * `php artisan <args>` in the live release, under the lock. On a terminal the
     * command gets the terminal (tinker, prompts); otherwise its output goes to $onLine.
     *
     * @param list<string> $args
     * @param (Closure(string): void)|null $onLine
     */
    public function artisan(string $site, array $args, bool $terminal, ?Closure $onLine = null): int
    {
        [, $live, $php] = $this->live($site);
        $lock = $this->lock($site, 'artisan');
        try {
            $options = new RunOptions(cwd: $live->dir, timeout: null, label: 'artisan ' . ($args[0] ?? ''));
            $options = $terminal ? $options->tty() : $options->streaming($onLine ?? static function (string $line): void {
            });

            return $this->shell->run([$php, 'artisan', ...$args], $options)->exitCode;
        } finally {
            $lock->release();
        }
    }

    /**
     * LAR-05 down in the live release. Returns the bypass URL when a secret is used.
     */
    public function down(string $site, ?int $retry, ?string $secret, Reporter $reporter): ?string
    {
        [$config, $live, $php] = $this->live($site);
        $lock = $this->lock($site, 'down');
        try {
            $options = $retry !== null ? '--retry=' . $retry : $config->maintenanceOptions();
            $secret = $secret !== null && $secret !== '' ? $secret : $config->maintenanceSecret();
            $reporter->start('Maintenance on');
            $result = $this->maintenance->down($live->dir, $php, $options, $secret, $this->options($live, 'artisan down', $reporter));
            if (!$result->successful()) {
                $reporter->fail('php artisan down failed');
                throw new CpdeployException(ErrorCode::ARTISAN, 'php artisan down failed: ' . implode(' ', $result->lastLines(3)), 'See the output above.');
            }
            $reporter->succeed($live->id);

            return $secret !== null ? 'https://' . $config->domain() . '/' . $secret : null;
        } finally {
            $lock->release();
        }
    }

    public function up(string $site, Reporter $reporter): void
    {
        [, $live, $php] = $this->live($site);
        $lock = $this->lock($site, 'up');
        try {
            $reporter->start('Maintenance off');
            $result = $this->maintenance->up($live->dir, $php, $this->options($live, 'artisan up', $reporter));
            if (!$result->successful()) {
                $reporter->fail('php artisan up failed');
                throw new CpdeployException(ErrorCode::ARTISAN, 'php artisan up failed: ' . implode(' ', $result->lastLines(3)), 'See the output above.');
            }
            $reporter->succeed($live->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Rebuild caches (`optimize`) or clear them (`optimize:clear`).
     */
    public function caches(string $site, bool $clear, Reporter $reporter): void
    {
        [, $live, $php] = $this->live($site);
        $lock = $this->lock($site, $clear ? 'optimize:clear' : 'optimize');
        try {
            $command = $clear ? 'optimize:clear' : 'optimize';
            $reporter->start($clear ? 'Clear caches' : 'Rebuild caches');
            $result = $this->artisan->run($live->dir, $php, [$command], $this->options($live, 'artisan ' . $command, $reporter));
            if (!$result->successful()) {
                $reporter->fail("php artisan {$command} failed");
                throw new CpdeployException(ErrorCode::ARTISAN, "php artisan {$command} failed", 'See the output above.');
            }
            $reporter->succeed($live->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * `migrate:status` output of the live release (read-only).
     */
    public function migrationStatus(string $site): string
    {
        [, $live, $php] = $this->live($site);
        $result = $this->artisan->run($live->dir, $php, ['migrate:status'], new RunOptions(cwd: $live->dir, timeout: (float) $this->config->timeout('artisan'), label: 'artisan migrate:status'));

        return trim($result->output());
    }

    public function pending(string $site): MigrationCheck
    {
        [, $live, $php] = $this->live($site);

        return $this->migrations->pending($live->dir, $php, self::major($live), new RunOptions(timeout: (float) $this->config->timeout('artisan')));
    }

    /**
     * *Run pending migrations…*: down (live) → migrate --force → up, like G1/G2.
     * The site comes back up even when a migration fails (then E_MIGRATE).
     *
     * @return list<string> the migrations that ran (as far as known)
     */
    public function migrate(string $site, Reporter $reporter): array
    {
        [$config, $live, $php] = $this->live($site);
        $before = $this->pending($site);
        $lock = $this->lock($site, 'migrate');
        try {
            $down = $config->step('maintenance') === 'with_migrations';
            if ($down) {
                $reporter->start('Maintenance on');
                $this->maintenance->down($live->dir, $php, $config->maintenanceOptions(), $config->maintenanceSecret(), $this->options($live, 'artisan down', $reporter));
                $reporter->succeed($live->id);
            }
            $reporter->start('Migrations');
            $options = $this->options($live, 'artisan migrate', $reporter)->withTimeout((float) $this->config->timeout('migrate'));
            $result = $this->artisan->run($live->dir, $php, ['migrate', '--force'], $options);
            if ($result->successful()) {
                $reporter->succeed($before->known ? count($before->pending) . ' ran' : 'done');
            } else {
                $reporter->fail('php artisan migrate failed');
            }
            if ($down) {
                $reporter->start('Maintenance off');
                $this->maintenance->up($live->dir, $php, $this->options($live, 'artisan up', $reporter));
                $reporter->succeed('');
            }
            if (!$result->successful()) {
                $after = $this->migrations->pending($live->dir, $php, self::major($live), new RunOptions(timeout: (float) $this->config->timeout('artisan')));
                $done = $before->known && $after->known ? array_values(array_diff($before->pending, $after->pending)) : [];
                throw new CpdeployException(
                    ErrorCode::MIGRATE,
                    'Migration failed — the site is back up.' . ($done !== [] ? ' These completed before the failure: ' . implode(', ', $done) . '.' : ''),
                    'Fix the migration; check php artisan migrate:status.',
                    liveAffected: true,
                );
            }

            return $before->known ? $before->pending : [];
        } finally {
            $lock->release();
        }
    }

    /**
     * The newest laravel.log (or laravel-YYYY-MM-DD.log) in shared storage: its
     * path and last lines, or null when there is none.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function log(string $site, int $lines = self::LOG_LINES): ?array
    {
        $this->sites->load($site);
        $dir = $this->paths->sharedDir($site) . '/storage/logs';
        $file = $dir . '/laravel.log';
        if (!is_file($file)) {
            $daily = glob($dir . '/laravel-*.log') ?: [];
            rsort($daily);
            $file = $daily[0] ?? null;
        }
        if ($file === null || !is_file($file)) {
            return null;
        }
        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        // Only the tail of a large log is read.
        fseek($handle, max(0, $size - 256 * 1024));
        $text = (string) stream_get_contents($handle);
        fclose($handle);
        $all = explode("\n", rtrim($text, "\n"));

        return [$file, array_slice($all, -$lines)];
    }

    /**
     * LAR-07: the scheduler line for cPanel → Cron Jobs. It uses `current`, so it
     * follows every release.
     */
    public function cronLine(string $site): string
    {
        $config = $this->sites->load($site);
        $php = $this->php->resolve($config->phpVersion(), $config->phpFamily())->binary;

        return sprintf('* * * * * %s %s/artisan schedule:run >> /dev/null 2>&1', $php, $this->paths->current($site));
    }

    private function options(Release $live, string $label, Reporter $reporter): RunOptions
    {
        return (new RunOptions(cwd: $live->dir, timeout: (float) $this->config->timeout('artisan'), label: $label))
            ->reporting(fn (string $line) => $reporter->line($line), fn () => $reporter->tick());
    }

    private function lock(string $site, string $action): Lock
    {
        return Lock::site($this->paths->siteLock($site), $site, $action, $this->system->userName(), Version::get(), $this->clock);
    }

    private static function major(Release $release): ?int
    {
        $version = $release->get('laravel');

        return is_string($version) && preg_match('/^v?(\d+)/', $version, $m) === 1 ? (int) $m[1] : null;
    }
}
