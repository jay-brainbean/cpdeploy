<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

/**
 * Which package manager a project uses (NODE-07).
 */
final class PackageManagerChoice
{
    public const NPM = 'npm';
    public const PNPM = 'pnpm';
    public const YARN = 'yarn';
    public const YARN_BERRY = 'yarn-berry';

    public function __construct(
        public readonly string $name,
        public readonly ?string $version,
        public readonly string $reason,
        public readonly ?string $yarnPath = null,
    ) {
    }
}
