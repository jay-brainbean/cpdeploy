<?php

declare(strict_types=1);

namespace Cpdeploy\Laravel;

use Cpdeploy\Support\RunOptions;

/**
 * Version-aware pending-migration detection (LAR-03):
 * - Laravel ≥ 11: `migrate:status --pending=3` — exit 3 = pending (listed), 0 = none;
 * - Laravel 9–10: `migrate:status`, lines "<name> …… Pending";
 * - Laravel 8: table rows "| No | <name> |";
 * - "Migration table not found" → every migration file is pending (fresh database);
 * - anything else → unknown, with the raw output kept for the log.
 */
final class MigrationStatus
{
    public function __construct(private readonly Artisan $artisan)
    {
    }

    public function pending(string $releaseDir, string $php, ?int $laravelMajor, RunOptions $options): MigrationCheck
    {
        $args = ['migrate:status'];
        if ($laravelMajor === null || $laravelMajor >= 11) {
            $args[] = '--pending=3';
        }
        $result = $this->artisan->run($releaseDir, $php, $args, new RunOptions(env: $options->env, timeout: $options->timeout, pathPrefix: $options->pathPrefix, label: 'migrate:status'));

        return self::parse($laravelMajor, $result->output(), $result->exitCode, self::migrationFiles($releaseDir));
    }

    /**
     * @param list<string> $migrationFiles names of the files in database/migrations
     */
    public static function parse(?int $laravelMajor, string $output, int $exitCode, array $migrationFiles = []): MigrationCheck
    {
        $output = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        if (str_contains($output, 'Migration table not found')) {
            return new MigrationCheck(true, $migrationFiles, $output);
        }

        if ($laravelMajor === null || $laravelMajor >= 11) {
            if ($exitCode === 0) {
                return new MigrationCheck(true, [], $output);
            }
            if ($exitCode === 3) {
                $pending = self::pendingLines($output);

                return $pending !== [] ? new MigrationCheck(true, $pending, $output) : MigrationCheck::unknown($output);
            }
            if ($laravelMajor !== null) {
                return MigrationCheck::unknown($output);
            }
            // Unknown version and --pending=3 was refused: fall through to the text formats.
        } elseif ($exitCode !== 0) {
            return MigrationCheck::unknown($output);
        }

        $pending = self::pendingLines($output);
        if ($pending !== [] || self::hasRanLines($output)) {
            return new MigrationCheck(true, $pending, $output);
        }
        $table = self::tableRows($output);
        if ($table !== null) {
            return new MigrationCheck(true, $table, $output);
        }
        if (preg_match('/No migrations found|No pending migrations/i', $output) === 1) {
            return new MigrationCheck(true, [], $output);
        }

        return MigrationCheck::unknown($output);
    }

    /**
     * @return list<string>
     */
    private static function pendingLines(string $output): array
    {
        preg_match_all('/^\s*(\S+)\s+\.{2,}.*\bPending\b/m', $output, $m);

        return array_values(array_unique($m[1]));
    }

    private static function hasRanLines(string $output): bool
    {
        return preg_match('/^\s*\S+\s+\.{2,}.*\bRan\b/m', $output) === 1;
    }

    /**
     * Laravel 8: "| Ran? | Migration | Batch |" table. Null when there is no table.
     *
     * @return list<string>|null
     */
    private static function tableRows(string $output): ?array
    {
        if (preg_match('/\|\s*Ran\?\s*\|\s*Migration\s*\|/', $output) !== 1) {
            return null;
        }
        preg_match_all('/^\|\s*No\s*\|\s*(\S+)\s*\|/m', $output, $m);

        return $m[1];
    }

    /**
     * @return list<string>
     */
    public static function migrationFiles(string $releaseDir): array
    {
        $names = [];
        foreach (glob($releaseDir . '/database/migrations/*.php') ?: [] as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);

        return $names;
    }
}
