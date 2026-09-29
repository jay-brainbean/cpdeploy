<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * `uapi --output=json <Module> <function> key=value …`, run as the cPanel user (CP-01).
 *
 * Facts from a real server (cPanel 11.138):
 * - uapi exits 0 even when the call fails; only result.status tells (1 = ok, 0 = failed);
 * - a failed call can print a "warn [uapi] …" line before the JSON;
 * - every argument value must be URI-encoded.
 */
final class Uapi
{
    public const TIMEOUT = 60.0;

    public function __construct(
        private readonly Shell $shell,
        private readonly string $binary = 'uapi',
    ) {
    }

    public function available(): bool
    {
        return $this->shell->which($this->binary) !== null;
    }

    /**
     * Calls a function and returns result.data.
     *
     * @param array<string, string|int> $args
     * @param int|null $exitCode exit code for E_UAPI (3 by default; 6 during go-live)
     */
    public function call(string $module, string $function, array $args = [], ?int $exitCode = null): mixed
    {
        $result = $this->callRaw($module, $function, $args);
        if (($result['status'] ?? 0) !== 1) {
            $errors = self::messages($result['errors'] ?? null);
            throw new CpdeployException(
                ErrorCode::UAPI,
                sprintf('cPanel refused %s::%s: %s', $module, $function, $errors !== [] ? implode('; ', $errors) : 'no reason given'),
                'See the message above; fix it in cPanel if it names a setting.',
                exitCode: $exitCode,
            );
        }

        return $result['data'] ?? null;
    }

    /**
     * Calls a function and returns the whole `result` object, successful or not.
     *
     * @param array<string, string|int> $args
     * @return array<string, mixed>
     */
    public function callRaw(string $module, string $function, array $args = []): array
    {
        if (!$this->available()) {
            throw new CpdeployException(
                ErrorCode::NOT_CPANEL,
                "This doesn't look like a cPanel account (uapi not found)",
                "Run cpdeploy inside a cPanel account's shell.",
            );
        }

        $argv = [$this->binary, '--output=json', $module, $function];
        foreach ($args as $key => $value) {
            $argv[] = $key . '=' . rawurlencode((string) $value);
        }
        $run = $this->shell->run($argv, new RunOptions(timeout: self::TIMEOUT, label: "uapi {$module}::{$function}"));

        $decoded = self::decode($run->stdout) ?? self::decode($run->stdout . "\n" . $run->stderr);
        if ($decoded === null || !is_array($decoded['result'] ?? null)) {
            $detail = trim($run->stderr !== '' ? $run->stderr : $run->stdout);
            throw new CpdeployException(
                ErrorCode::UAPI,
                sprintf('cPanel refused %s::%s: %s', $module, $function, $run->timedOut ? 'no answer within 60s' : ($detail !== '' ? mb_substr($detail, 0, 300) : 'no output')),
                'Try again; if it repeats, check that cPanel is working (log in to cPanel in a browser).',
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];

        return $result;
    }

    /**
     * Finds the JSON document in uapi output, skipping any warning lines before it.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $output): ?array
    {
        $output = trim($output);
        $candidates = [$output];
        if (preg_match('/^\{.*$/ms', $output, $m) === 1) {
            $candidates[] = $m[0];
        }
        foreach ($candidates as $candidate) {
            $data = json_decode($candidate, true);
            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return $data;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function messages(mixed $list): array
    {
        if (is_string($list) && $list !== '') {
            return [$list];
        }
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
