<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * What a deploy did, for the command's summary and exit code (§10.3).
 */
final class DeployResult
{
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const FAILED = 'failed';
    public const NOTHING = 'nothing';

    /**
     * @param list<string> $notes
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $result,
        public readonly int $exitCode,
        public readonly ?string $releaseId = null,
        public readonly ?string $url = null,
        public readonly float $duration = 0.0,
        public readonly array $notes = [],
        public readonly array $warnings = [],
        public readonly ?string $logPath = null,
        public readonly string $message = '',
    ) {
    }
}
