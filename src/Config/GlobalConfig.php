<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Config\Schema\GlobalSchema;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * ~/cpdeploy/config.yml (§8.1). Never holds secrets (SEC-02).
 */
final class GlobalConfig
{
    public const HEADER = '# Managed by cpdeploy. Edit with "cpdeploy config <site> edit" or the menus.';

    /**
     * @param array<string, mixed> $data
     * @param list<string> $warnings
     */
    private function __construct(private array $data, public readonly array $warnings = [])
    {
    }

    public static function defaults(): self
    {
        return new self(GlobalSchema::defaults());
    }

    /**
     * Loads, migrates and validates config.yml. A missing file means defaults.
     */
    public static function load(string $file, Fs $fs): self
    {
        if (!is_file($file)) {
            return self::defaults();
        }
        $raw = (string) file_get_contents($file);
        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                sprintf('%s has problems: %s', $file, $e->getMessage()),
                'Fix the YAML syntax in the file, or delete it to go back to the defaults.',
            );
        }
        if ($data === null) {
            $data = [];
        }
        if (!is_array($data)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} has problems: it is not a YAML mapping", 'Fix the file, or delete it to go back to the defaults.');
        }
        /** @var array<string, mixed> $data */
        $schema = $data['schema'] ?? GlobalSchema::VERSION;
        if (!is_int($schema) || $schema < 1) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} has problems: schema must be a positive whole number", 'Set schema: ' . GlobalSchema::VERSION);
        }
        if ($schema > GlobalSchema::VERSION) {
            throw new CpdeployException(ErrorCode::CONFIG_NEWER, "{$file} was written by a newer cpdeploy", 'Run: cpdeploy self-update');
        }

        $migrated = GlobalSchema::needsMigration($schema);
        if ($migrated) {
            $fs->writeAtomic($file . '.schema' . $schema . '.bak', $raw, Paths::MODE_SECRET_FILE);
            $data = GlobalSchema::migrate($data, $schema);
        }

        $data = GlobalSchema::withDefaults($data);
        $result = GlobalSchema::validate($data);
        if ($result['errors'] !== []) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                sprintf("%s has problems:\n  - %s", $file, implode("\n  - ", $result['errors'])),
                'Fix these values in the file (or in Settings).',
            );
        }

        $config = new self($data, $result['warnings']);
        if ($migrated) {
            $config->save($file, $fs);
        }

        return $config;
    }

    public function save(string $file, Fs $fs): void
    {
        $fs->writeAtomic($file, self::HEADER . "\n" . Yaml::dump($this->data, 4, 2), Paths::MODE_SECRET_FILE);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function timeout(string $key): int
    {
        $value = $this->data['timeouts'][$key] ?? null;

        return is_int($value) ? $value : (int) (GlobalSchema::defaults()['timeouts'][$key] ?? 300);
    }

    public function mirror(string $key): string
    {
        $value = $this->data['mirrors'][$key] ?? '';

        return rtrim(is_string($value) ? $value : '', '/');
    }

    /**
     * ui.unicode / ui.color: null = auto.
     */
    public function uiFlag(string $key): ?bool
    {
        $value = $this->data['ui'][$key] ?? 'auto';

        return is_bool($value) ? $value : null;
    }

    public function editor(): string
    {
        $value = $this->data['ui']['editor'] ?? '';

        return is_string($value) ? $value : '';
    }

    public function updateRepo(): string
    {
        $value = $this->data['update']['repo'] ?? GlobalSchema::DEFAULT_UPDATE_REPO;

        return is_string($value) ? $value : GlobalSchema::DEFAULT_UPDATE_REPO;
    }

    public function defaultKeepReleases(): int
    {
        $value = $this->data['defaults']['keep_releases'] ?? 5;

        return is_int($value) ? $value : 5;
    }

    public function defaultFlag(string $key): bool
    {
        return ($this->data['defaults'][$key] ?? true) === true;
    }
}
