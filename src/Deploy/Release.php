<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Fs;

/**
 * One release folder and its .release.json (§8.5).
 * Status: building → ready → live → ready; building → failed.
 */
final class Release
{
    public const BUILDING = 'building';
    public const READY = 'ready';
    public const LIVE = 'live';
    public const FAILED = 'failed';
    /** A folder without readable metadata (e.g. killed before B1 finished). */
    public const UNKNOWN = 'unknown';

    public const META = '.release.json';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $dir,
        private array $data,
    ) {
    }

    public static function load(string $dir): self
    {
        $id = basename($dir);
        $raw = @file_get_contents($dir . '/' . self::META);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = ['schema' => 1, 'id' => $id, 'status' => self::UNKNOWN];
        }
        /** @var array<string, mixed> $data */
        return new self($id, $dir, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $ref = &$this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($part, $ref)) {
                $ref[$part] = null;
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        unset($ref);
    }

    public function save(Fs $fs): void
    {
        $fs->writeAtomic(
            $this->dir . '/' . self::META,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            Paths::MODE_PUBLIC_FILE,
        );
    }

    public function status(): string
    {
        $s = $this->get('status');

        return is_string($s) ? $s : self::UNKNOWN;
    }

    public function isProtected(): bool
    {
        return $this->get('protected') === true;
    }

    public function commit(): ?string
    {
        $c = $this->get('commit');

        return is_string($c) && $c !== '' ? $c : null;
    }

    public function short(): string
    {
        $s = $this->get('short');

        return is_string($s) ? $s : substr((string) $this->commit(), 0, 7);
    }

    public function message(): string
    {
        $m = $this->get('message');

        return is_string($m) ? $m : '';
    }

    /**
     * PHP binary recorded at build time (PHP-07).
     */
    public function phpBinary(): ?string
    {
        $b = $this->get('php.binary');

        return is_string($b) && $b !== '' ? $b : null;
    }

    /**
     * major.minor of the PHP the release was built with, e.g. "8.2".
     */
    public function phpMajorMinor(): ?string
    {
        $v = $this->get('php.version');
        if (!is_string($v) || preg_match('/^(\d+\.\d+)/', $v, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public function phpFamily(): ?string
    {
        $f = $this->get('php.family');

        return is_string($f) ? $f : null;
    }

    public function createdAt(): ?string
    {
        $c = $this->get('created_at');

        return is_string($c) ? $c : null;
    }

    /**
     * Age in seconds from the created_at timestamp, or from the id when missing.
     */
    public function ageSeconds(int $now): int
    {
        $created = $this->createdAt();
        $time = $created !== null ? strtotime($created) : false;
        if ($time === false && preg_match('/^(\d{8})-(\d{6})/', $this->id, $m) === 1) {
            $time = strtotime($m[1] . 'T' . $m[2] . 'Z');
        }

        return $time === false ? PHP_INT_MAX : $now - $time;
    }

    public function has(string $relative): bool
    {
        $path = $this->dir . ($relative === '' ? '' : '/' . $relative);

        return file_exists($path) || is_link($path);
    }

    /**
     * The folder that is served: <release>/<web_dir>.
     */
    public function webPath(string $webDir): string
    {
        return $webDir === '' ? $this->dir : $this->dir . '/' . $webDir;
    }
}
