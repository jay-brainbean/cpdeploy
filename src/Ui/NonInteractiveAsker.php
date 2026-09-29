<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * No TTY or -n (NI-01). With --yes every question takes its default (NI-02, NI-03);
 * otherwise any question is an error that exits 2.
 */
final class NonInteractiveAsker implements Asker
{
    public function __construct(private readonly bool $yes = false)
    {
    }

    public function select(string $label, array $options, int|string|null $default = null, string $hint = ''): int|string
    {
        if ($this->yes && $default !== null) {
            return $default;
        }
        throw $this->needs($label);
    }

    public function multiselect(string $label, array $options, array $default = [], string $hint = ''): array
    {
        if ($this->yes) {
            return $default;
        }
        throw $this->needs($label);
    }

    public function search(string $label, Closure $options, string $placeholder = '', string $hint = ''): int|string
    {
        throw $this->needs($label);
    }

    public function text(string $label, string $default = '', string $placeholder = '', bool $required = false, ?Closure $validate = null, string $hint = ''): string
    {
        if ($this->yes && ($default !== '' || !$required)) {
            return $default;
        }
        throw $this->needs($label);
    }

    public function password(string $label, string $hint = ''): string
    {
        throw $this->needs($label);
    }

    public function textarea(string $label, string $default = '', string $hint = ''): string
    {
        if ($this->yes && $default !== '') {
            return $default;
        }
        throw $this->needs($label);
    }

    public function confirm(string $label, bool $default = true, string $hint = ''): bool
    {
        if ($this->yes) {
            return $default;
        }
        throw $this->needs($label);
    }

    public function pause(string $message = 'Press Enter to continue'): void
    {
    }

    public function interactive(): bool
    {
        return false;
    }

    private function needs(string $label): CpdeployException
    {
        return new CpdeployException(
            ErrorCode::NEEDS_ANSWER,
            sprintf('This needs an answer: "%s"', $label),
            'Pass the matching flags, or --yes to accept the defaults.',
        );
    }
}
