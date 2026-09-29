<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Cpdeploy\Config\Paths;
use RuntimeException;

/**
 * One operation's log file (LOG-01): header, every step with its commands and
 * output, footer. Everything written passes through the Masker (LOG-04).
 */
final class Log
{
    public const KEEP = 50;

    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    private function __construct(
        $handle,
        public readonly string $path,
        private readonly Masker $masker,
        private readonly Clock $clock,
    ) {
        $this->handle = $handle;
    }

    /**
     * @param array<string, string> $header e.g. tool version, Tool PHP, site, user, host
     */
    public static function open(string $dir, string $action, Masker $masker, Clock $clock, array $header): self
    {
        if (!is_dir($dir) && !@mkdir($dir, Paths::MODE_PRIVATE_DIR, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create log folder {$dir}");
        }
        $base = $dir . '/' . $clock->stamp() . '-' . $action;
        $path = $base . '.log';
        for ($i = 2; file_exists($path); $i++) {
            $path = $base . '-' . $i . '.log';
        }

        $old = umask(0177);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            throw new RuntimeException("Cannot create log file {$path}");
        }
        chmod($path, Paths::MODE_SECRET_FILE);

        $log = new self($handle, $path, $masker, $clock);
        $log->write(sprintf('cpdeploy %s — started %s', $action, $clock->iso()));
        foreach ($header as $key => $value) {
            $log->write(sprintf('%s: %s', $key, $value));
        }
        $log->write(str_repeat('-', 60));

        return $log;
    }

    public function write(string $line): void
    {
        if ($this->handle === null) {
            return;
        }
        $stamp = $this->clock->now()->format('H:i:s');
        fwrite($this->handle, '[' . $stamp . '] ' . $this->masker->mask($line) . "\n");
    }

    public function close(string $result, int $exitCode): void
    {
        if ($this->handle === null) {
            return;
        }
        $this->write(str_repeat('-', 60));
        $this->write(sprintf('result: %s, exit code %d, finished %s', $result, $exitCode, $this->clock->iso()));
        $handle = $this->handle;
        $this->handle = null;
        if ($handle !== null) {
            fclose($handle);
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * LOG-02: keep the newest $keep log files in $dir.
     */
    public static function prune(string $dir, int $keep = self::KEEP): int
    {
        $files = glob($dir . '/*.log') ?: [];
        sort($files);
        $removed = 0;
        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $file) {
            if (!is_link($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * LOG-03 / §8.6: append one JSON object to history.jsonl (600). Never truncated.
     *
     * @param array<string, mixed> $entry
     */
    public static function appendHistory(string $file, array $entry, Masker $masker): void
    {
        $line = $masker->mask((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
        $old = umask(0177);
        try {
            $ok = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } finally {
            umask($old);
        }
        if ($ok === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        @chmod($file, Paths::MODE_SECRET_FILE);
    }

    /**
     * Reads history entries, newest last. Malformed lines are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public static function readHistory(string $file, ?int $last = null): array
    {
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($last !== null) {
            $lines = array_slice($lines, -$last);
        }
        $entries = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                $entries[] = $data;
            }
        }

        return $entries;
    }
}
