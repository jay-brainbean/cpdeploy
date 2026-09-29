<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Result of one HTTP request made with curl. Status 0 means no response
 * (DNS, connection, TLS or timeout error; see $curlExit and $error).
 */
final class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers lower-cased names, from the last response in a redirect chain
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $curlExit = 0,
        public readonly string $error = '',
        public readonly int $attempts = 1,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function networkError(): bool
    {
        return $this->status === 0;
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[count($values) - 1];
    }

    /**
     * @return mixed decoded JSON body, or null when it isn't JSON
     */
    public function json(): mixed
    {
        $data = json_decode($this->body, true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * A TLS certificate problem (curl exits 35, 51, 58, 60, 77, 83, 90, 91).
     */
    public function tlsError(): bool
    {
        return in_array($this->curlExit, [35, 51, 58, 60, 77, 83, 90, 91], true);
    }
}
