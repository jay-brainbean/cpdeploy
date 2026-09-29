<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Support\Log;
use Cpdeploy\Ui\Reporter;

/**
 * One recovery (§11.9): the state file's contents and what was done so far.
 */
final class RecoveryRun
{
    public readonly string $operation;
    public readonly string $phase;

    /** @var list<string> what recovery did */
    public array $actions = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> what the user should check (REC-03) */
    public array $checks = [];

    /**
     * @param array<string, mixed> $data the state file (§8.7)
     */
    public function __construct(
        public SiteConfig $site,
        private readonly array $data,
        private readonly Reporter $reporter,
        private readonly Log $log,
    ) {
        $this->operation = is_string($data['operation'] ?? null) ? $data['operation'] : StateFile::DEPLOY;
        $this->phase = is_string($data['phase'] ?? null) ? $data['phase'] : 'unknown';
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function flag(string $key): bool
    {
        return ($this->data[$key] ?? false) === true;
    }

    /**
     * The release the operation was making live (the new release, or the rollback target).
     */
    public function release(): ?string
    {
        return $this->string('release');
    }

    public function liveBefore(): ?string
    {
        return $this->string('live_before');
    }

    public function act(string $text): void
    {
        $this->actions[] = $text;
        $this->reporter->info($text);
        $this->log->write('RECOVER: ' . $text);
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
        $this->reporter->warn($text);
        $this->log->write('WARNING: ' . $text);
    }

    public function check(string $text): void
    {
        $this->checks[] = $text;
        $this->log->write('CHECK: ' . $text);
    }
}
