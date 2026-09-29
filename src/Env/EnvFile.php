<?php

declare(strict_types=1);

namespace Cpdeploy\Env;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * Lossless .env parser (ENV-01, ENV-02). Every line is kept as written (blank
 * lines, comments, order, `export `, quoting), so an unchanged file serialises
 * back byte for byte.
 *
 * Values: unquoted (with an optional " #comment"), single-quoted (literal) or
 * double-quoted (\\, \" and \n escapes; may span lines). Like Laravel's dotenv,
 * an unquoted value with whitespace inside is an error, and the first
 * definition of a key wins.
 */
final class EnvFile
{
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    /**
     * @param list<array{key: ?string, value: ?string, text: string, line: int}> $entries
     * @param list<string> $warnings
     */
    private function __construct(
        private readonly array $entries,
        public readonly array $warnings,
    ) {
    }

    /**
     * @throws CpdeployException E_ENV_PARSE with the line number
     */
    public static function parse(string $content, string $name = '.env'): self
    {
        $lines = explode("\n", $content);
        $entries = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $entries[] = ['key' => null, 'value' => null, 'text' => $line, 'line' => $i + 1];
                continue;
            }
            if (preg_match('/^\s*(?:export\s+)?([^=\s]+)\s*=\s*(.*)$/s', rtrim($line, "\r"), $m) !== 1 || preg_match(self::KEY_PATTERN, $m[1]) !== 1) {
                throw self::error($name, $i + 1, 'expected KEY=VALUE');
            }
            $key = $m[1];
            $rest = $m[2];
            $start = $i;

            if (str_starts_with($rest, '"') || str_starts_with($rest, "'")) {
                $quote = $rest[0];
                $buffer = substr($rest, 1);
                $value = null;
                while (true) {
                    $end = self::closingQuote($buffer, $quote);
                    if ($end !== null) {
                        $tail = trim(substr($buffer, $end + 1));
                        if ($tail !== '' && !str_starts_with($tail, '#')) {
                            throw self::error($name, $start + 1, "unexpected text after the closing quote of {$key}");
                        }
                        $raw = substr($buffer, 0, $end);
                        $value = $quote === '"' ? self::unescape($raw) : $raw;
                        break;
                    }
                    if ($i + 1 >= $count) {
                        throw self::error($name, $start + 1, "the quoted value of {$key} is never closed");
                    }
                    $i++;
                    $buffer .= "\n" . rtrim($lines[$i], "\r");
                }
            } else {
                $value = trim((string) preg_replace('/(^|\s)#.*$/', '', $rest));
                if (preg_match('/\s/', $value) === 1) {
                    throw self::error($name, $start + 1, "the value of {$key} contains spaces; put it in double quotes");
                }
            }

            $text = implode("\n", array_slice($lines, $start, $i - $start + 1));
            $entries[] = ['key' => $key, 'value' => $value, 'text' => $text, 'line' => $start + 1];
        }

        $seen = [];
        foreach ($entries as $entry) {
            if ($entry['key'] !== null) {
                $seen[$entry['key']][] = $entry['line'];
            }
        }
        $warnings = [];
        foreach ($seen as $key => $at) {
            if (count($at) > 1) {
                $warnings[] = sprintf('%s is set more than once (lines %s); the first one is used', $key, implode(', ', $at));
            }
        }

        return new self($entries, $warnings);
    }

    public static function fromFile(string $file): self
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new CpdeployException(ErrorCode::ENV_MISSING, "{$file} is missing or unreadable", 'Create it: Manage site → Environment.');
        }

        return self::parse($content, $file);
    }

    public function get(string $key): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['key'] === $key) {
                return $entry['value'];
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Every key → value (the first definition wins).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry['key'] !== null && !array_key_exists($entry['key'], $out)) {
                $out[$entry['key']] = (string) $entry['value'];
            }
        }

        return $out;
    }

    /**
     * Keys in file order (first definitions only).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * ENV-03: a copy with KEY set to $value, quoted by ENV-04.
     *  1. an active line → its value is replaced (an `export ` prefix is kept);
     *  2. else a commented assignment `# KEY=…` becomes the active line;
     *  3. else it goes after the last key with the same prefix (before the first
     *     `_`), or at the end.
     */
    public function set(string $key, string $value): self
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new CpdeployException(ErrorCode::USAGE, "{$key} isn't a valid .env key", 'Keys use letters, digits, _ and ., and do not start with a digit.');
        }
        $line = $key . '=' . self::quote($key, $value);
        $entries = $this->entries;

        foreach ($entries as $i => $entry) {
            if ($entry['key'] === $key) {
                $export = preg_match('/^\s*export\s+/', $entry['text']) === 1 ? 'export ' : '';
                $entries[$i] = ['key' => $key, 'value' => $value, 'text' => $export . $line, 'line' => $entry['line']];

                return new self($entries, $this->warnings);
            }
        }
        foreach ($entries as $i => $entry) {
            if ($entry['key'] === null && preg_match('/^\s*#\s*(?:export\s+)?' . preg_quote($key, '/') . '\s*=/', $entry['text']) === 1) {
                $entries[$i] = ['key' => $key, 'value' => $value, 'text' => $line, 'line' => $entry['line']];

                return new self($entries, $this->warnings);
            }
        }

        $new = ['key' => $key, 'value' => $value, 'text' => $line, 'line' => 0];
        $prefix = explode('_', $key)[0];
        $after = null;
        foreach ($entries as $i => $entry) {
            if ($entry['key'] !== null && explode('_', $entry['key'])[0] === $prefix) {
                $after = $i;
            }
        }
        if ($after === null) {
            // At the end, before the empty line a trailing newline leaves.
            $after = count($entries) - 1;
            while ($after >= 0 && $entries[$after]['key'] === null && trim($entries[$after]['text']) === '') {
                $after--;
            }
        }
        array_splice($entries, $after + 1, 0, [$new]);

        return new self($entries, $this->warnings);
    }

    /**
     * A copy without any active line for KEY (commented lines stay).
     */
    public function unset(string $key): self
    {
        return new self(array_values(array_filter($this->entries, static fn (array $e): bool => $e['key'] !== $key)), $this->warnings);
    }

    /**
     * ENV-04: unquoted when safe; single quotes when there is a `$` (no ${VAR}
     * interpolation); else double quotes with escapes. `$` together with `'`
     * can't be written safely and is refused.
     */
    public static function quote(string $key, string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/:@+,-]*$/', $value) === 1) {
            return $value;
        }
        if (str_contains($value, '$')) {
            if (str_contains($value, "'")) {
                throw new CpdeployException(
                    ErrorCode::USAGE,
                    "The value of {$key} contains both \$ and ': it can't be written without \$ being expanded",
                    'Change the value, or edit .env by hand (cpdeploy env <site> edit).',
                );
            }

            return "'" . $value . "'";
        }

        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
    }

    public function toString(): string
    {
        return implode("\n", array_map(static fn (array $e): string => $e['text'], $this->entries));
    }

    private static function closingQuote(string $buffer, string $quote): ?int
    {
        $length = strlen($buffer);
        for ($p = 0; $p < $length; $p++) {
            if ($quote === '"' && $buffer[$p] === '\\') {
                $p++;
                continue;
            }
            if ($buffer[$p] === $quote) {
                return $p;
            }
        }

        return null;
    }

    private static function unescape(string $raw): string
    {
        return (string) preg_replace_callback('/\\\\(.)/s', static fn (array $m): string => match ($m[1]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '"' => '"',
            '\\' => '\\',
            '$' => '$',
            default => '\\' . $m[1],
        }, $raw);
    }

    private static function error(string $name, int $line, string $what): CpdeployException
    {
        return new CpdeployException(
            ErrorCode::ENV_PARSE,
            "{$name} has a syntax error on line {$line}: {$what}",
            'Fix it: Manage site → Environment (or edit the file).',
        );
    }
}
