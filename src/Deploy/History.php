<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Log;

/**
 * Logs & history (§9.5.12, `cpdeploy logs`): history.jsonl entries and the
 * operation logs they point at.
 */
final class History
{
    public const SITE_ENTRIES = 20;
    public const ALL_ENTRIES = 30;

    /** Results that count as failures for the *Failures only* filter. */
    public const FAILURES = ['failed', 'interrupted'];

    public function __construct(
        private readonly Paths $paths,
        private readonly SiteRegistry $sites,
    ) {
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $site, bool $failuresOnly = false, int $limit = self::SITE_ENTRIES): array
    {
        $this->sites->load($site);
        $entries = array_reverse(Log::readHistory($this->paths->history($site)));
        if ($failuresOnly) {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => in_array($e['result'] ?? null, self::FAILURES, true)));
        }

        return array_slice($entries, 0, $limit);
    }

    /**
     * The last operations of every site, newest first, each with its `site`.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = self::ALL_ENTRIES): array
    {
        $all = [];
        foreach ($this->sites->names() as $site) {
            foreach (Log::readHistory($this->paths->history($site), $limit) as $entry) {
                $all[] = ['site' => $site] + $entry;
            }
        }
        usort($all, static fn (array $a, array $b): int => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));

        return array_slice($all, 0, $limit);
    }

    /**
     * The absolute log path of an entry, or null.
     *
     * @param array<string, mixed> $entry
     */
    public function logOf(string $site, array $entry): ?string
    {
        $log = $entry['log'] ?? null;
        if (!is_string($log) || $log === '' || str_contains($log, '..')) {
            return null;
        }
        $path = $this->paths->siteDir($site) . '/' . $log;

        return is_file($path) ? $path : null;
    }

    /**
     * A log by id: a log file name (with or without .log), or a release id (its newest log).
     */
    public function find(string $site, string $id): string
    {
        $this->sites->load($site);
        $dir = $this->paths->logsDir($site);
        $name = basename($id);
        foreach ([$name, $name . '.log'] as $candidate) {
            if (is_file($dir . '/' . $candidate)) {
                return $dir . '/' . $candidate;
            }
        }
        // A release id: the newest operation on that release (log names carry the
        // time the log was opened, not the release id).
        foreach ($this->entries($site, false, PHP_INT_MAX) as $entry) {
            if (($entry['release'] ?? null) === $name) {
                $log = $this->logOf($site, $entry);
                if ($log !== null) {
                    return $log;
                }
            }
        }
        $matches = glob($dir . '/' . $name . '-*.log') ?: [];
        rsort($matches);
        if ($matches === []) {
            throw new CpdeployException(ErrorCode::USAGE, "{$site} has no log {$id}", "List them with: cpdeploy logs {$site}");
        }

        return $matches[0];
    }

    /**
     * The newest log file, or null.
     */
    public function last(string $site, bool $failuresOnly = false): ?string
    {
        foreach ($this->entries($site, $failuresOnly, PHP_INT_MAX) as $entry) {
            $log = $this->logOf($site, $entry);
            if ($log !== null) {
                return $log;
            }
        }

        return null;
    }
}
