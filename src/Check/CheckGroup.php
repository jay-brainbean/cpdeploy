<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

final class CheckGroup
{
    /**
     * @param list<CheckResult> $checks
     */
    public function __construct(public readonly string $name, public readonly array $checks)
    {
    }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === CheckResult::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, checks: list<array{id: string, status: string, message: string, hint: string}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $this->checks),
        ];
    }
}
