<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

/**
 * What a recovery did (REC-03): the actions taken and what the user should check.
 */
final class RecoveryResult
{
    public const NOTHING = 'nothing';

    /**
     * @param list<string> $actions
     * @param list<string> $checks
     */
    public function __construct(
        public readonly string $result,
        public readonly string $operation,
        public readonly string $phase,
        public readonly ?string $liveId,
        public readonly array $actions = [],
        public readonly array $checks = [],
        public readonly ?string $logPath = null,
    ) {
    }
}
