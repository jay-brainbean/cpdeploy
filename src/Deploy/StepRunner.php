<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Deploy\Steps\Step;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Signals;
use Cpdeploy\Ui\Format;
use Throwable;

/**
 * Runs one step with its reporting (ENG-03: start → line* → succeed | fail),
 * records its duration, and turns a Ctrl+C between steps into E_CANCELLED.
 */
final class StepRunner
{
    public function __construct(private readonly Signals $signals)
    {
    }

    public function run(DeployContext $ctx, Step $step): void
    {
        if (!$step->applies($ctx)) {
            return;
        }
        $label = $step->label($ctx);
        $ctx->reporter->start($label);
        $ctx->log?->write('== ' . $label);
        $started = hrtime(true);
        try {
            $detail = $step->run($ctx);
        } catch (CpdeployException $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            throw $e;
        } catch (Throwable $e) {
            $ctx->reporter->fail($e->getMessage(), $ctx->log?->path);
            throw new CpdeployException(ErrorCode::INTERNAL, "{$label} failed: " . $e->getMessage(), 'This is a bug in cpdeploy. See the log.', previous: $e);
        }
        $seconds = (hrtime(true) - $started) / 1e9;
        $ctx->durations[$step->key()] = ($ctx->durations[$step->key()] ?? 0) + (int) round($seconds);
        $ctx->reporter->succeed($detail);
        $ctx->log?->write(sprintf('✓ %s%s (%s)', $label, $detail !== '' ? ' · ' . $detail : '', Format::duration($seconds)));

        if ($this->signals->cancelRequested()) {
            throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled — your live site was not changed', '');
        }
    }
}
