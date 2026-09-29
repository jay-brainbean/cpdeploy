<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

/**
 * A small JSON Schema checker for the subset resources/schemas/ uses: type
 * (with null), const, enum, required, properties and items. Returns every
 * problem found, as "path: message".
 */
final class JsonShape
{
    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function problems(mixed $value, array $schema, string $path = '$'): array
    {
        $problems = [];
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return ["{$path}: expected " . json_encode($schema['const'])];
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return ["{$path}: " . json_encode($value) . ' is not one of ' . json_encode($schema['enum'])];
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $type) {
                $ok = $ok || self::is($value, (string) $type);
            }
            if (!$ok) {
                return ["{$path}: expected " . implode('|', $types) . ', got ' . get_debug_type($value)];
            }
        }
        if (is_array($value) && !array_is_list($value) || (is_array($value) && $value === [] && isset($schema['properties']))) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (!array_key_exists((string) $key, $value)) {
                    $problems[] = "{$path}: missing {$key}";
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $key => $sub) {
                if (array_key_exists($key, $value) && is_array($sub)) {
                    /** @var array<string, mixed> $sub */
                    $problems = [...$problems, ...self::problems($value[$key], $sub, "{$path}.{$key}")];
                }
            }
        } elseif (is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                /** @var array<string, mixed> $items */
                $items = $schema['items'];
                $problems = [...$problems, ...self::problems($item, $items, "{$path}[{$i}]")];
            }
        }

        return $problems;
    }

    private static function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => false,
        };
    }
}
