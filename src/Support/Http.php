<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Closure;
use Cpdeploy\Version;
use RuntimeException;

/**
 * All HTTP traffic goes through the curl binary (HTTP-01):
 * - the status comes from `-w %{http_code}`, headers from `-D <file>`;
 * - redirects are followed (at most 5); connect timeout 10 s, `--max-time` per call;
 * - secret headers are written to curl's config on stdin (`--config -`), never argv (SEC-03);
 * - proxies (https_proxy) are honoured by curl itself.
 * GETs are retried twice on network errors or 5xx, after 1 s then 3 s (HTTP-02).
 */
final class Http
{
    /** @var list<int> seconds to wait before each retry */
    public const RETRY_DELAYS = [1, 3];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep test hook for the retry backoff
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, string> $headers       sent on the command line: never put secrets here
     * @param array<string, string> $secretHeaders sent through `--config -` on stdin
     * @param list<string>          $resolve       `host:port:ip` entries (HTTP-03)
     */
    public function get(
        string $url,
        array $headers = [],
        array $secretHeaders = [],
        float $timeout = 30,
        array $resolve = [],
        bool $insecure = false,
        ?string $saveTo = null,
    ): HttpResponse {
        $attempt = 0;
        while (true) {
            $attempt++;
            $response = $this->request('GET', $url, $headers, $secretHeaders, null, $timeout, $resolve, $insecure, $saveTo, $attempt);
            $retry = $response->networkError() || $response->status >= 500;
            if (!$retry || $attempt > count(self::RETRY_DELAYS)) {
                return $response;
            }
            ($this->sleep)(self::RETRY_DELAYS[$attempt - 1]);
        }
    }

    /**
     * Any method; never retried automatically (HTTP-02).
     *
     * @param array<string, string> $headers
     * @param array<string, string> $secretHeaders
     * @param list<string>          $resolve `host:port:ip` entries (HTTP-03)
     */
    public function send(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        array $secretHeaders = [],
        float $timeout = 30,
        array $resolve = [],
        bool $insecure = false,
    ): HttpResponse {
        return $this->request(strtoupper($method), $url, $headers, $secretHeaders, $body, $timeout, $resolve, $insecure, null, 1);
    }

    /**
     * Downloads $url to $file. On failure the partial file is removed.
     */
    public function download(string $url, string $file, float $timeout = 600): HttpResponse
    {
        $response = $this->get($url, timeout: $timeout, saveTo: $file);
        if (!$response->ok()) {
            @unlink($file);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $secretHeaders
     * @param list<string>          $resolve
     */
    private function request(
        string $method,
        string $url,
        array $headers,
        array $secretHeaders,
        ?string $body,
        float $timeout,
        array $resolve,
        bool $insecure,
        ?string $saveTo,
        int $attempt,
    ): HttpResponse {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new RuntimeException("Only http(s) URLs are allowed: {$url}");
        }

        $headerFile = $this->fs->tempFile('http-headers');
        $bodyFile = $saveTo ?? $this->fs->tempFile('http-body');
        $dataFile = null;

        try {
            $argv = [
                'curl', '-sS', '--location', '--max-redirs', '5',
                '--connect-timeout', '10', '--max-time', (string) max(1, (int) ceil($timeout)),
                '-D', $headerFile,
                '-o', $bodyFile,
                '-w', '%{http_code}',
                '-A', 'cpdeploy/' . Version::get(),
            ];
            if ($method !== 'GET') {
                $argv[] = '-X';
                $argv[] = $method;
            }
            foreach ($headers as $name => $value) {
                $argv[] = '-H';
                $argv[] = $name . ': ' . $value;
            }
            foreach ($resolve as $entry) {
                $argv[] = '--resolve';
                $argv[] = $entry;
            }
            if ($insecure) {
                $argv[] = '-k';
            }
            if ($body !== null) {
                $dataFile = $this->fs->tempFile('http-data', $body);
                $argv[] = '--data-binary';
                $argv[] = '@' . $dataFile;
            }

            // The URL was checked to start with http(s)://, so it can't be read as an option.
            $argv[] = $url;

            $input = null;
            if ($secretHeaders !== []) {
                $argv[] = '--config';
                $argv[] = '-';
                $input = '';
                foreach ($secretHeaders as $name => $value) {
                    $input .= 'header = "' . addcslashes($name . ': ' . $value, '"\\') . "\"\n";
                }
            }

            $result = $this->shell->run($argv, new RunOptions(timeout: $timeout + 15, input: $input, label: 'curl'));
            $status = (int) trim($result->stdout);
            $responseHeaders = self::parseHeaders((string) @file_get_contents($headerFile));
            $responseBody = $saveTo === null ? (string) @file_get_contents($bodyFile) : '';

            if ($result->exitCode !== 0 && $status === 0) {
                return new HttpResponse(0, $responseHeaders, $responseBody, $result->exitCode, trim($result->stderr), $attempt);
            }

            return new HttpResponse($status, $responseHeaders, $responseBody, $result->exitCode, trim($result->stderr), $attempt);
        } finally {
            @unlink($headerFile);
            if ($saveTo === null) {
                @unlink($bodyFile);
            }
            if ($dataFile !== null) {
                @unlink($dataFile);
            }
        }
    }

    /**
     * Headers of the last response when curl followed redirects.
     *
     * @return array<string, list<string>>
     */
    public static function parseHeaders(string $raw): array
    {
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw)) ?: [];
        $last = '';
        foreach ($blocks as $block) {
            if (preg_match('#^HTTP/\S+\s+\d+#', ltrim($block)) === 1) {
                $last = $block;
            }
        }
        $headers = [];
        foreach (preg_split("/\r?\n/", $last) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || str_starts_with($line, 'HTTP/')) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
        }

        return $headers;
    }
}
