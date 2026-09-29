<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Ui\Format;
use Cpdeploy\Version;
use Throwable;

/**
 * Phase D (§11.7): prune releases (PR-01), logs, .env backups and tmp (PR-02),
 * then the history entry (PR-03). Cleanup problems are warnings only (PR-04).
 */
final class Finisher
{
    public const ENV_BACKUPS_KEEP = 10;

    public function __construct(
        private readonly ReleaseManager $releases,
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Masker $masker,
        private readonly Clock $clock,
    ) {
    }

    public function cleanup(DeployContext $ctx): void
    {
        $ctx->state?->phase(StateFile::FINISHING);
        $ctx->reporter->start('Cleanup');
        try {
            $pruned = $this->releases->prune($ctx->name(), $ctx->site->keepReleases(), $ctx->release?->id);
            foreach ($pruned['problems'] as $problem) {
                $ctx->warn($problem);
            }
            Log::prune($this->paths->logsDir($ctx->name()));
            $this->pruneEnvBackups($ctx->name());
            $this->fs->cleanTmp();
            $detail = sprintf('kept %d release%s, removed %d', $pruned['kept'], $pruned['kept'] === 1 ? '' : 's', $pruned['removed']);
            if ($pruned['freed_kb'] > 0) {
                $detail .= ' (freed ' . Format::kilobytes($pruned['freed_kb']) . ')';
            }
            $ctx->reporter->succeed($detail);
        } catch (Throwable $e) {
            $ctx->reporter->succeed('');
            $ctx->warn('Cleanup problem: ' . $e->getMessage());
        }
    }

    /**
     * §8.6: one line in history.jsonl.
     *
     * @param list<string> $notes
     */
    public function history(
        string $site,
        string $action,
        string $result,
        int $exitCode,
        ?string $release,
        ?string $fromRelease,
        ?string $commit,
        ?string $fromCommit,
        float $duration,
        ?string $log,
        string $user,
        array $notes,
    ): void {
        try {
            Log::appendHistory($this->paths->history($site), [
                'ts' => $this->clock->iso(),
                'action' => $action,
                'result' => $result,
                'exit_code' => $exitCode,
                'release' => $release,
                'from_release' => $fromRelease,
                'commit' => $commit !== null ? substr($commit, 0, 7) : null,
                'from_commit' => $fromCommit !== null ? substr($fromCommit, 0, 7) : null,
                'duration_s' => (int) round($duration),
                'log' => $log !== null ? substr($log, strlen($this->paths->siteDir($site)) + 1) : null,
                'user' => $user,
                'tool' => Version::get(),
                'notes' => $notes,
            ], $this->masker);
        } catch (Throwable) {
            // PR-04: never changes the result.
        }
    }

    private function pruneEnvBackups(string $site): void
    {
        $files = glob($this->paths->envBackupsDir($site) . '/.env.*') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::ENV_BACKUPS_KEEP)) as $file) {
            if (!is_link($file)) {
                @unlink($file);
            }
        }
    }
}
