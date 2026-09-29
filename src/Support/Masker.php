<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Replaces secrets with •••• before anything reaches a log, the terminal or JSON
 * (LOG-04, SEC-02). Loaded with the current secrets at the start of each operation.
 */
final class Masker
{
    public const MASK = '••••';

    /** Values shorter than this are not masked: they would mangle ordinary output. */
    public const MIN_LENGTH = 4;

    /** ENV-05: .env keys whose values are secret. */
    public const SECRET_KEY_PATTERN = '/(PASS|PASSWORD|SECRET|TOKEN|KEY|PRIVATE|CREDENTIAL|AUTH|DSN)/i';

    /** @var array<string, true> */
    private array $secrets = [];

    public function add(?string $secret): void
    {
        if ($secret === null) {
            return;
        }
        $secret = trim($secret);
        if (strlen($secret) < self::MIN_LENGTH) {
            return;
        }
        $this->secrets[$secret] = true;
        // Also mask the forms a secret takes inside URLs and JSON strings.
        $this->secrets[rawurlencode($secret)] = true;
        $json = substr((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
        if ($json !== '') {
            $this->secrets[$json] = true;
        }
    }

    /**
     * Adds the values of every .env key that matches ENV-05.
     *
     * @param array<string, string> $env
     */
    public function addEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            if (self::isSecretKey($key)) {
                $this->add($value);
            }
        }
    }

    /**
     * COMPOSER_AUTH is JSON; mask the whole document and every string inside it.
     */
    public function addComposerAuth(?string $json): void
    {
        if ($json === null || trim($json) === '') {
            return;
        }
        $this->add($json);
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function (mixed $value): void {
                if (is_string($value)) {
                    $this->add($value);
                }
            });
        }
    }

    public static function isSecretKey(string $key): bool
    {
        return preg_match(self::SECRET_KEY_PATTERN, $key) === 1;
    }

    public function clear(): void
    {
        $this->secrets = [];
    }

    public function mask(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        if ($this->secrets !== []) {
            $secrets = array_keys($this->secrets);
            // Longest first, so a secret containing another is replaced whole.
            usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $text = str_replace($secrets, self::MASK, $text);
        }

        // Authorization headers, in any case, with or without a scheme.
        $text = (string) preg_replace_callback(
            '/(authorization\s*:\s*)(?:(bearer|token|basic)\s+)?[^\s"\']+/i',
            static fn (array $m): string => $m[1] . (($m[2] ?? '') !== '' ? $m[2] . ' ' : '') . self::MASK,
            $text,
        );
        // https://user:pass@host → https://••••@host
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1' . self::MASK . '@', $text);

        return $text;
    }

    /**
     * @param list<string> $argv
     */
    public function maskCommand(array $argv): string
    {
        return $this->mask(implode(' ', array_map([Shell::class, 'quote'], $argv)));
    }
}
