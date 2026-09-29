<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Shell;

/**
 * B7: `php artisan optimize` inside the new release, before go-live (D6), so the
 * site switches to a release whose caches are already built.
 */
final class OptimizeStep implements Step
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly Shell $shell,
    ) {
    }

    public function key(): string
    {
        return 'optimize';
    }

    public function label(DeployContext $ctx): string
    {
        return 'optimize';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->optimize;
    }

    public function run(DeployContext $ctx): string
    {
        $options = $ctx->options($ctx->timeout('artisan'), 'artisan optimize');
        $result = $this->artisan->run($ctx->releaseDir(), $ctx->sitePhp()->binary, ['optimize'], $options);
        if (!$result->successful()) {
            throw $this->shell->failure(
                $result,
                $options,
                ErrorCode::ARTISAN,
                'php artisan optimize failed',
                'Route caching fails with closure routes; a service provider may query the database at boot. See the log.',
            );
        }
        $major = $ctx->info?->laravelMajor();

        return $major !== null && $major < 11 ? 'config · routes' : 'config · events · routes · views';
    }
}
