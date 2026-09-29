<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Fs;

/**
 * .deploy-state.json (§8.7, LCK-03): the phase of a running deploy or rollback,
 * written atomically before every step that can affect the live site (INV-08).
 * Present while the site lock is free = an interrupted operation (REC-01).
 */
final class StateFile
{
    /** Deploy phases, in order (§8.7). */
    public const PLANNING = 'planning';
    public const BUILDING = 'building';
    public const MAINTENANCE = 'maintenance';
    public const MIGRATING = 'migrating';
    public const MULTIPHP = 'multiphp';
    public const SWITCHING = 'switching';
    public const CONVERTING_DOCROOT = 'converting_docroot';
    public const SWITCHED = 'switched';
    public const FINISHING = 'finishing';

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string $file,
        private readonly Fs $fs,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($data)) {
            return ['schema' => 1, 'operation' => 'unknown', 'phase' => 'unknown'];
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Starts a new operation record.
     *
     * @param array<string, mixed> $fields
     */
    public function begin(string $operation, string $startedAt, array $fields = []): void
    {
        $this->data = array_merge([
            'schema' => 1,
            'operation' => $operation,
            'pid' => getmypid(),
            'started_at' => $startedAt,
            'phase' => self::PLANNING,
            'release' => null,
            'live_before' => null,
            'maintenance_on' => null,
            'migrations_started' => false,
            'multiphp_before' => null,
            'multiphp_after' => null,
            'docroot_converted' => false,
            'switched' => false,
        ], $fields);
        $this->write();
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        foreach ($changes as $key => $value) {
            $this->data[$key] = $value;
        }
        $this->write();
    }

    public function phase(string $phase): void
    {
        $this->update(['phase' => $phase]);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function delete(): void
    {
        @unlink($this->file);
        $this->data = [];
    }

    private function write(): void
    {
        $this->fs->writeAtomic($this->file, json_encode($this->data, JSON_UNESCAPED_SLASHES) . "\n", Paths::MODE_SECRET_FILE);
    }
}
