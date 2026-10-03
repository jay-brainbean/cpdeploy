<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Signals;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

/**
 * Interactive answers through Laravel Prompts. Labels are cut to 74 columns (§5.3).
 * Once the terminal is gone (SIGHUP, end of input) or SIGTERM arrived, the next
 * prompt exits the process: nobody is there to answer it.
 */
final class PromptsAsker implements Asker
{
    public function __construct(private readonly Signals $signals)
    {
        $terminal = new PromptTerminal($signals);
        Closure::bind(static function (Terminal $terminal): void {
            static::$terminal = $terminal;
        }, null, Prompt::class)($terminal);

        // Prompts reads Ctrl+C as a key (stty -isig) and would exit(1). Turn it into
        // E_CANCELLED instead, so flows can clean up (UI-02) and exit 130.
        Prompt::cancelUsing(static function (): never {
            Prompt::terminal()->restoreTty();
            throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', 'Nothing was changed.');
        });
    }

    public function select(string $label, array $options, int|string|null $default = null, string $hint = ''): int|string
    {
        $this->guard();
        $labels = array_map(static fn (string $o): string => Format::truncate($o), $options);

        return select(label: Format::truncate($label), options: $labels, default: $default, scroll: 10, hint: $hint);
    }

    public function multiselect(string $label, array $options, array $default = [], string $hint = ''): array
    {
        $this->guard();
        $labels = array_map(static fn (string $o): string => Format::truncate($o), $options);

        return array_values(multiselect(label: Format::truncate($label), options: $labels, default: $default, scroll: 10, hint: $hint));
    }

    public function search(string $label, Closure $options, string $placeholder = '', string $hint = ''): int|string
    {
        $this->guard();
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
        $this->guard();
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
        $this->guard();
        return password(label: Format::truncate($label), hint: $hint);
    }

    public function textarea(string $label, string $default = '', string $hint = ''): string
    {
        $this->guard();
        return textarea(label: Format::truncate($label), default: $default, hint: $hint);
    }

    public function confirm(string $label, bool $default = true, string $hint = ''): bool
    {
        $this->guard();
        return confirm(label: Format::truncate($label), default: $default, hint: $hint);
    }

    public function pause(string $message = 'Press Enter to continue'): void
    {
        $this->guard();
        pause($message);
    }

    public function interactive(): bool
    {
        return true;
    }

    /**
     * After a hang-up or SIGTERM the first prompt already ended with E_CANCELLED,
     * so the flow could clean up. A flow that asks again would wait forever.
     */
    private function guard(): void
    {
        $signal = $this->signals->terminating();
        if ($signal !== null) {
            exit(128 + $signal);
        }
    }
}
