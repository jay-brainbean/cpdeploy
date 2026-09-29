<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Commands\LogsCommand;

/**
 * Logs & history (§9.5.12 for one site; §9.2 for all sites): the table, an
 * entry's log in the pager, and the *Failures only* filter.
 */
final class LogsMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function site(string $site): void
    {
        $history = $this->ctx->services->history();
        $failures = false;
        while (true) {
            $this->ctx->title($site, 'Logs & history' . ($failures ? ' (failures only)' : ''));
            $entries = $history->entries($site, $failures);
            if ($entries === []) {
                $this->ctx->line($failures ? 'No failed operations.' : 'No history yet.');
            } else {
                LogsCommand::table($this->ctx->output, $entries, $this->ctx->services->clock()->now(), $this->ctx->theme);
            }
            $options = [];
            foreach ($entries as $i => $entry) {
                if ($history->logOf($site, $entry) !== null) {
                    $options['e' . $i] = sprintf('%s · %s · %s', $this->ctx->when($entry['ts'] ?? null), (string) ($entry['action'] ?? '?'), (string) ($entry['result'] ?? '?'));
                }
            }
            $options['filter'] = $failures ? 'Show everything' : 'Failures only';
            $choice = (string) $this->ctx->choose('Open a log', $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            if ($choice === 'filter') {
                $failures = !$failures;
                continue;
            }
            $this->open($site, $entries[(int) substr($choice, 1)] ?? []);
        }
    }

    /**
     * §9.2: the last 30 operations across all sites.
     */
    public function all(): void
    {
        $history = $this->ctx->services->history();
        while (true) {
            $this->ctx->title('Logs & history');
            $entries = $history->recent();
            if ($entries === []) {
                $this->ctx->line('No operations yet.');
                $this->ctx->pause();

                return;
            }
            LogsCommand::table($this->ctx->output, $entries, $this->ctx->services->clock()->now(), $this->ctx->theme);
            $options = [];
            foreach ($entries as $i => $entry) {
                if ($history->logOf((string) $entry['site'], $entry) !== null) {
                    $options['e' . $i] = sprintf('%s · %s · %s · %s', (string) $entry['site'], $this->ctx->when($entry['ts'] ?? null), (string) ($entry['action'] ?? '?'), (string) ($entry['result'] ?? '?'));
                }
            }
            $choice = (string) $this->ctx->choose('Open a log', $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $entry = $entries[(int) substr($choice, 1)] ?? [];
            $this->open((string) ($entry['site'] ?? ''), $entry);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function open(string $site, array $entry): void
    {
        $log = $site !== '' ? $this->ctx->services->history()->logOf($site, $entry) : null;
        if ($log === null) {
            return;
        }
        $this->ctx->services->pager($this->ctx->output)->show((string) file_get_contents($log), $this->ctx->asker->interactive());
    }
}
