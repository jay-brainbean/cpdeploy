<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;

/**
 * One build or go-live step (§11.4, §11.5). The runner reports start/succeed/fail
 * (ENG-03) and records the duration under key().
 */
interface Step
{
    /**
     * Key for .release.json → durations, e.g. "composer".
     */
    public function key(): string;

    public function label(DeployContext $ctx): string;

    public function applies(DeployContext $ctx): bool;

    /**
     * Runs the step; returns the detail shown after ✓ (may be "").
     */
    public function run(DeployContext $ctx): string;
}
