<?php

declare(strict_types=1);

namespace Cpdeploy\Laravel;

use Cpdeploy\Support\ProcessResult;
use Cpdeploy\Support\RunOptions;

/**
 * LAR-05: maintenance mode per release. down/up run with that release's PHP
 * (PHP-07); the caller picks the binary.
 */
final class Maintenance
{
    public function __construct(private readonly Artisan $artisan)
    {
    }

    public function down(string $releaseDir, string $php, string $options, ?string $secret, RunOptions $run): ProcessResult
    {
        $args = ['down', ...array_values(array_filter(preg_split('/\s+/', trim($options)) ?: [], static fn (string $a): bool => $a !== ''))];
        if ($secret !== null && $secret !== '') {
            $args[] = '--secret=' . $secret;
        }

        return $this->artisan->run($releaseDir, $php, $args, $run->withLabel('artisan down'));
    }

    /**
     * Any exit code is only a warning for the caller: "already up" is fine.
     */
    public function up(string $releaseDir, string $php, RunOptions $run): ProcessResult
    {
        return $this->artisan->run($releaseDir, $php, ['up'], $run->withLabel('artisan up'));
    }

    public static function isDown(string $releaseDir): bool
    {
        return file_exists($releaseDir . '/storage/framework/down');
    }
}
