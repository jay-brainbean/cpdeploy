<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactive answers through Laravel Prompts. Labels are cut to 74 columns (§5.3).
 */
final class PromptsAsker implements Asker
{
    public function __construct()
    {
        // Prompts reads Ctrl+C as a key (stty -isig) and would exit(1). Turn it into
        // E_CANCELLED instead, so flows can clean up (UI-02) and exit 130.
        Prompt::cancelUsing(static function (): never {
            Prompt::terminal()->restoreTty();
            throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', 'Nothing was changed.');
        });
    }

    public function select(string $label, array $options, int|string|null $default = null, string $hint = ''): int|string
    {
        $labels = array_map(static fn (string $o): string => Format::truncate($o), $options);

        return select(label: Format::truncate($label), options: $labels, default: $default, scroll: 10, hint: $hint);
    }

    public function search(string $label, Closure $options, string $placeholder = '', string $hint = ''): int|string
    {
        return search(
            label: Format::truncate($label),
            options: static fn (string $value): array => array_map(
                static fn (string $o): string => Format::truncate($o),
                $options($value),
            ),
            placeholder: $placeholder,
            scroll: 10,
            hint: $hint,
        );
    }

    public function text(string $label, string $default = '', string $placeholder = '', bool $required = false, ?Closure $validate = null, string $hint = ''): string
    {
        return text(
            label: Format::truncate($label),
            placeholder: $placeholder,
            default: $default,
            required: $required,
            validate: $validate,
            hint: $hint,
        );
    }

    public function password(string $label, string $hint = ''): string
    {
        return password(label: Format::truncate($label), hint: $hint);
    }

    public function confirm(string $label, bool $default = true, string $hint = ''): bool
    {
        return confirm(label: Format::truncate($label), default: $default, hint: $hint);
    }

    public function pause(string $message = 'Press Enter to continue'): void
    {
        pause($message);
    }

    public function interactive(): bool
    {
        return true;
    }
}
