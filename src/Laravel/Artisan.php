<?php

declare(strict_types=1);

namespace Cpdeploy\Laravel;

use Cpdeploy\Support\ProcessResult;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * LAR-01: `<php> artisan <command> --no-interaction` in a release, through Shell
 * with the caller's shims on PATH. `--ansi` when the output is shown live,
 * `--no-ansi` when it is parsed.
 */
final class Artisan
{
    public function __construct(private readonly Shell $shell)
    {
    }

    /**
     * @param list<string> $args e.g. ['migrate', '--force']
     */
    public function run(string $releaseDir, string $php, array $args, RunOptions $options): ProcessResult
    {
        $command = [$php, 'artisan', ...$args, '--no-interaction', $options->mode === RunOptions::STREAM ? '--ansi' : '--no-ansi'];

        return $this->shell->run($command, $options->withCwd($releaseDir)->withLabel($options->label !== '' ? $options->label : 'artisan ' . ($args[0] ?? '')));
    }
}
