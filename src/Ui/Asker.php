<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;

/**
 * How services get answers without calling Laravel Prompts directly (ARC-04).
 */
interface Asker
{
    /**
     * @param array<int|string, string> $options value => label
     */
    public function select(string $label, array $options, int|string|null $default = null, string $hint = ''): int|string;

    /**
     * @param array<int|string, string> $options value => label
     * @param list<int|string>           $default values selected at first
     * @return list<int|string> the selected values
     */
    public function multiselect(string $label, array $options, array $default = [], string $hint = ''): array;

    /**
     * @param Closure(string): array<int|string, string> $options search text → value => label
     */
    public function search(string $label, Closure $options, string $placeholder = '', string $hint = ''): int|string;

    /**
     * @param Closure(string): ?string|null $validate returns an error message, or null when valid
     */
    public function text(string $label, string $default = '', string $placeholder = '', bool $required = false, ?Closure $validate = null, string $hint = ''): string;

    public function password(string $label, string $hint = ''): string;

    public function confirm(string $label, bool $default = true, string $hint = ''): bool;

    public function pause(string $message = 'Press Enter to continue'): void;

    public function interactive(): bool;
}
