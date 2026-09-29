<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

/**
 * One line of `cpdeploy check`: ✓ / ⚠ / ✗ (or info) + message + fix hint (§9.7).
 */
final class CheckResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const INFO = 'info';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $message,
        public readonly string $hint = '',
    ) {
    }

    public static function ok(string $id, string $message): self
    {
        return new self($id, self::OK, $message);
    }

    public static function warn(string $id, string $message, string $hint = ''): self
    {
        return new self($id, self::WARN, $message, $hint);
    }

    public static function fail(string $id, string $message, string $hint = ''): self
    {
        return new self($id, self::FAIL, $message, $hint);
    }

    public static function info(string $id, string $message): self
    {
        return new self($id, self::INFO, $message);
    }

    /**
     * @return array{id: string, status: string, message: string, hint: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'message' => $this->message, 'hint' => $this->hint];
    }
}
