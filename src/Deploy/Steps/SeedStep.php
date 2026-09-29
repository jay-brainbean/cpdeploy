<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Shell;

/**
 * G3: `php artisan db:seed --force` in the new release, after migrations (PLN-04).
 */
final class SeedStep implements Step
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly Shell $shell,
    ) {
    }

    public function key(): string
    {
        return 'seed';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Seed';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->seed;
    }

    public function run(DeployContext $ctx): string
    {
        $options = $ctx->options($ctx->timeout('migrate'), 'artisan db:seed');
        $result = $this->artisan->run($ctx->releaseDir(), $ctx->sitePhp()->binary, ['db:seed', '--force'], $options);
        if (!$result->successful()) {
            throw $this->shell->failure($result, $options, ErrorCode::MIGRATE, 'php artisan db:seed failed', 'Fix the seeder; the database may need attention.');
        }

        return '';
    }
}
