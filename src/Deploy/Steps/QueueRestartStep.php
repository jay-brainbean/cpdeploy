<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Laravel\Artisan;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Shell;

/**
 * G10: `php artisan queue:restart` in the live release (steps.queue_restart: every).
 * Off by default (D6).
 */
final class QueueRestartStep implements Step
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly Shell $shell,
    ) {
    }

    public function key(): string
    {
        return 'queue_restart';
    }

    public function label(DeployContext $ctx): string
    {
        return 'queue:restart';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->site->isLaravel() && $ctx->site->step('queue_restart') === 'every';
    }

    public function run(DeployContext $ctx): string
    {
        $cwd = $ctx->paths->current($ctx->name());
        $options = $ctx->options($ctx->timeout('artisan'), 'artisan queue:restart', cwd: $cwd);
        $result = $this->artisan->run($cwd, $ctx->sitePhp()->binary, ['queue:restart'], $options);
        if (!$result->successful()) {
            throw $this->shell->failure($result, $options, ErrorCode::ARTISAN, 'php artisan queue:restart failed', 'See the log.');
        }

        return '';
    }
}
