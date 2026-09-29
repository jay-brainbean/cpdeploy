<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Config\CustomCommand;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Shell;

/**
 * B8 / G10: one custom command, `bash -c <run>` with the user's permissions
 * (SEC-10). before_activate runs in the new release, after_activate in `current`;
 * the site shims and Node are on PATH. on_error: fail stops (B8) or marks the
 * deploy failed without a rollback (G10); warn only warns.
 */
final class CustomCommandStep implements Step
{
    public function __construct(
        private readonly Shell $shell,
        private readonly CustomCommand $command,
    ) {
    }

    public function key(): string
    {
        return 'custom_' . $this->command->index;
    }

    public function label(DeployContext $ctx): string
    {
        return $this->command->name;
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->runsCustom($this->command->index);
    }

    public function run(DeployContext $ctx): string
    {
        $cwd = $this->command->phase === CustomCommand::AFTER ? $ctx->paths->current($ctx->name()) : $ctx->releaseDir();
        $timeout = (float) ($this->command->timeout ?? $ctx->timeout('custom'));
        $options = $ctx->options($timeout, $this->command->name, withNode: true, cwd: $cwd);
        $result = $this->shell->run(['bash', '-c', $this->command->run], $options);
        if ($result->successful()) {
            return '';
        }
        if ($this->command->onError === 'warn' && !$result->cancelled) {
            $ctx->warn(sprintf('custom command "%s" failed (on_error: warn)', $this->command->name));

            return 'failed (warning only)';
        }
        throw $this->shell->failure($result, $options, ErrorCode::CUSTOM, sprintf('Custom command "%s" failed', $this->command->name), 'See the last lines above and the log.');
    }

    public function command(): CustomCommand
    {
        return $this->command;
    }
}
