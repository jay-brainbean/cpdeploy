<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Shell;

/**
 * B6: `php artisan storage:link` in the new release, every deploy (D6): each
 * release has a fresh public/ folder. "already exists" counts as success (LAR-04).
 */
final class StorageLinkStep implements Step
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly Shell $shell,
    ) {
    }

    public function key(): string
    {
        return 'storage_link';
    }

    public function label(DeployContext $ctx): string
    {
        return 'storage:link';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->storageLink;
    }

    public function run(DeployContext $ctx): string
    {
        $options = $ctx->options($ctx->timeout('artisan'), 'artisan storage:link');
        $result = $this->artisan->run($ctx->releaseDir(), $ctx->sitePhp()->binary, ['storage:link'], $options);
        if (!$result->successful() && !str_contains($result->output(), 'already exists')) {
            throw $this->shell->failure($result, $options, ErrorCode::ARTISAN, 'php artisan storage:link failed', 'See the last lines above and the log.');
        }

        return '';
    }
}
