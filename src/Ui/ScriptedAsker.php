<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Closure;
use LogicException;

/**
 * Test double: answers come from a queue, in order. Every question asked is recorded.
 * An answer may be a [labelFragment, value] pair to assert which question it answers.
 */
final class ScriptedAsker implements Asker
{
    /** @var list<mixed> */
    private array $answers;

    /** @var list<array{type: string, label: string}> */
    public array $asked = [];

    /**
     * @param list<mixed> $answers
     */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
    }

    public function push(mixed ...$answers): void
    {
        foreach ($answers as $answer) {
            $this->answers[] = $answer;
        }
    }

    public function remaining(): int
    {
        return count($this->answers);
    }

    public function select(string $label, array $options, int|string|null $default = null, string $hint = ''): int|string
    {
        $answer = $this->next('select', $label);
        if (!is_int($answer) && !is_string($answer)) {
            throw new LogicException("Scripted answer for \"{$label}\" must be an option value");
        }
        if (!array_key_exists($answer, $options)) {
            throw new LogicException(sprintf('Scripted answer "%s" is not an option of "%s": %s', $answer, $label, implode(', ', array_keys($options))));
        }

        return $answer;
    }

    public function multiselect(string $label, array $options, array $default = [], string $hint = ''): array
    {
        $answer = $this->next('multiselect', $label);
        if (!is_array($answer)) {
            throw new LogicException("Scripted answer for \"{$label}\" must be a list of option values");
        }
        $out = [];
        foreach ($answer as $value) {
            if ((!is_int($value) && !is_string($value)) || !array_key_exists($value, $options)) {
                throw new LogicException(sprintf('Scripted answer "%s" is not an option of "%s": %s', is_scalar($value) ? (string) $value : '?', $label, implode(', ', array_keys($options))));
            }
            $out[] = $value;
        }

        return $out;
    }

    public function search(string $label, Closure $options, string $placeholder = '', string $hint = ''): int|string
    {
        $answer = $this->next('search', $label);
        if (!is_int($answer) && !is_string($answer)) {
            throw new LogicException("Scripted answer for \"{$label}\" must be an option value");
        }

        return $answer;
    }

    public function text(string $label, string $default = '', string $placeholder = '', bool $required = false, ?Closure $validate = null, string $hint = ''): string
    {
        $answer = $this->next('text', $label);
        $answer = $answer === null ? $default : (string) (is_scalar($answer) ? $answer : '');
        if ($validate !== null && ($error = $validate($answer)) !== null) {
            throw new LogicException("Scripted answer \"{$answer}\" for \"{$label}\" fails validation: {$error}");
        }

        return $answer;
    }

    public function password(string $label, string $hint = ''): string
    {
        $answer = $this->next('password', $label);

        return is_scalar($answer) ? (string) $answer : '';
    }

    public function textarea(string $label, string $default = '', string $hint = ''): string
    {
        $answer = $this->next('textarea', $label);

        return $answer === null ? $default : (is_scalar($answer) ? (string) $answer : '');
    }

    public function confirm(string $label, bool $default = true, string $hint = ''): bool
    {
        $answer = $this->next('confirm', $label);

        return $answer === null ? $default : (bool) $answer;
    }

    public function pause(string $message = 'Press Enter to continue'): void
    {
        $this->asked[] = ['type' => 'pause', 'label' => $message];
    }

    public function interactive(): bool
    {
        return true;
    }

    private function next(string $type, string $label): mixed
    {
        $this->asked[] = ['type' => $type, 'label' => $label];
        if ($this->answers === []) {
            throw new LogicException("Unexpected question ({$type}): {$label}");
        }
        $answer = array_shift($this->answers);
        if (is_array($answer) && count($answer) === 2 && is_string($answer[0] ?? null) && array_key_exists(1, $answer)) {
            if (!str_contains($label, $answer[0])) {
                throw new LogicException("Expected a question containing \"{$answer[0]}\", got: {$label}");
            }

            return $answer[1];
        }

        return $answer;
    }
}
