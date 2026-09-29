<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

/**
 * One entry of site.yml custom_commands (§8.2). `run` goes through `bash -c` with
 * the user's own permissions (SEC-10).
 */
final class CustomCommand
{
    public const BEFORE = 'before_activate';
    public const AFTER = 'after_activate';

    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $run,
        public readonly string $phase = self::BEFORE,
        public readonly string $when = 'every',
        public readonly ?int $timeout = null,
        public readonly string $onError = 'fail',
    ) {
    }

    /**
     * Flag name used to answer an `ask` command without a TTY.
     */
    public function key(): string
    {
        return 'custom:' . $this->index;
    }
}
