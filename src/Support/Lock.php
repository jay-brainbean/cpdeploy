<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use DateTimeImmutable;
use RuntimeException;

/**
 * flock-based locks (§7.19). The kernel releases a flock when the process dies,
 * so a crash can never leave a site locked.
 */
final class Lock
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    private function __construct($handle, private readonly string $file)
    {
        $this->handle = $handle;
    }

    /**
     * LCK-01: exclusive, non-blocking. Throws E_LOCKED with the holder's details.
     */
    public static function site(string $file, string $site, string $action, string $user, string $tool, Clock $clock): self
    {
        $handle = self::open($file);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = self::readHolder($handle);
            fclose($handle);
            $started = $holder['started_at'] ?? null;
            $when = $started !== null ? self::localTime($started) : 'unknown time';
            throw new CpdeployException(
                ErrorCode::LOCKED,
                sprintf(
                    'Another cpdeploy operation (%s, started %s by PID %s) is running for %s',
                    $holder['action'] ?? 'unknown',
                    $when,
                    $holder['pid'] ?? '?',
                    $site,
                ),
                'Wait for it to finish.',
            );
        }

        $lock = new self($handle, $file);
        $lock->writeHolder([
            'pid' => getmypid(),
            'action' => $action,
            'started_at' => $clock->iso(),
            'user' => $user,
            'tool' => $tool,
        ]);

        return $lock;
    }

    /**
     * LCK-02: blocking with a deadline (default 10 minutes), for tools/ downloads.
     */
    public static function blocking(string $file, float $timeout = 600.0): self
    {
        $handle = self::open($file);
        $deadline = microtime(true) + $timeout;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new CpdeployException(
                    ErrorCode::LOCKED,
                    'Another cpdeploy process has been downloading tools for too long',
                    'Wait for it to finish, then try again.',
                );
            }
            usleep(200_000);
        }

        return new self($handle, $file);
    }

    /**
     * Whether someone currently holds the lock (used for REC-01 and UIG-06).
     */
    public static function isHeld(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function file(): string
    {
        return $this->file;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @return resource
     */
    private static function open(string $file)
    {
        $old = umask(0177);
        try {
            $handle = @fopen($file, 'c+');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file {$file}");
        }
        @chmod($file, Paths::MODE_SECRET_FILE);

        return $handle;
    }

    /**
     * @param array<string, mixed> $holder
     */
    private function writeHolder(array $holder): void
    {
        if ($this->handle === null) {
            return;
        }
        ftruncate($this->handle, 0);
        rewind($this->handle);
        fwrite($this->handle, (string) json_encode($holder, JSON_UNESCAPED_SLASHES) . "\n");
        fflush($this->handle);
    }

    /**
     * @param resource $handle
     * @return array<string, string>
     */
    private static function readHolder($handle): array
    {
        rewind($handle);
        $data = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    private static function localTime(string $iso): string
    {
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('H:i');
        } catch (\Exception) {
            return $iso;
        }
    }
}
