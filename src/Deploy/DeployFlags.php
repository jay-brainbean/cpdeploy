<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * The options of `cpdeploy deploy` (§10.2). The same object is filled by the
 * deploy screen (M5), so menus and the command share one code path (ARC-03).
 */
final class DeployFlags
{
    public const AUTO = 'auto';
    public const YES = 'yes';
    public const NO = 'no';

    /**
     * @param array<string, bool> $answers pre-set answers by question key (Retry, tests)
     */
    public function __construct(
        public readonly ?string $ref = null,
        public readonly string $composer = self::AUTO,
        public readonly string $migrate = self::AUTO,
        public readonly string $seed = self::AUTO,
        public readonly bool $skipBuild = false,
        public readonly bool $skipOptimize = false,
        public readonly bool $force = false,
        public readonly bool $allowRewind = false,
        public readonly bool $noHealthCheck = false,
        public readonly ?string $onHealthFail = null,
        public readonly bool $recover = false,
        public readonly bool $yes = false,
        public readonly array $answers = [],
    ) {
        foreach (['composer' => $composer, 'migrate' => $migrate, 'seed' => $seed] as $name => $value) {
            if (!in_array($value, [self::AUTO, self::YES, self::NO], true)) {
                throw new CpdeployException(ErrorCode::USAGE, "--{$name} must be auto, yes or no", 'Example: cpdeploy deploy shop --' . $name . '=yes');
            }
        }
        if ($onHealthFail !== null && !in_array($onHealthFail, ['ask', 'rollback', 'keep'], true)) {
            throw new CpdeployException(ErrorCode::USAGE, '--on-health-fail must be ask, rollback or keep', 'Example: --on-health-fail=keep');
        }
    }
}
