<?php

declare(strict_types=1);

namespace Cpdeploy\Support\Errors;

use RuntimeException;
use Throwable;

/**
 * The only exception type shown to users (ARC-09). Carries what happened (message),
 * whether the live site was affected, and what to do next (hint) — §0 rule 7.
 */
final class CpdeployException extends RuntimeException
{
    private readonly int $exitCode;

    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly string $hint = '',
        public readonly bool $liveAffected = false,
        ?int $exitCode = null,
        public readonly ?string $logPath = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->exitCode = $exitCode ?? $errorCode->exitCode();
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function withLogPath(string $logPath): self
    {
        return new self(
            $this->errorCode,
            $this->getMessage(),
            $this->hint,
            $this->liveAffected,
            $this->exitCode,
            $logPath,
            $this->getPrevious(),
        );
    }
}
