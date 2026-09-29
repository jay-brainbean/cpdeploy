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
