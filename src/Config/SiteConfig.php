<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Git\RepoUrl;

/**
 * Typed, immutable view of a validated site.yml (§8.2). with() returns a copy
 * with one value changed; SiteRegistry::save() writes it.
 */
final class SiteConfig
{
    /**
     * @param array<string, mixed> $data merged with the defaults and validated
     * @param list<string> $warnings unknown keys and similar (CFG-03)
     */
    public function __construct(private readonly array $data, public readonly array $warnings = [])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * A value by dotted path (e.g. "php.version"), or $default when absent.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        return $node;
    }

    public function with(string $path, mixed $value): self
    {
        $data = $this->data;
        $ref = &$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($key, $ref)) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);

        /** @var array<string, mixed> $data */
        return new self($data, $this->warnings);
    }

    public function name(): string
    {
        return $this->str('name');
    }

    public function type(): string
    {
        return $this->str('type');
    }

    public function isLaravel(): bool
    {
        return $this->type() === 'laravel';
    }

    public function repo(): RepoUrl
    {
        return new RepoUrl($this->str('repo.owner'), $this->str('repo.name'));
    }

    public function branch(): string
    {
        return $this->str('repo.branch');
    }

    public function transport(): string
    {
        return $this->str('repo.transport');
    }

    public function deployKeyId(): ?int
    {
        $id = $this->get('repo.deploy_key_id');

        return is_int($id) ? $id : null;
    }

    public function domain(): string
    {
        return $this->str('domain.name');
    }

    public function docroot(): string
    {
        return rtrim($this->str('domain.docroot'), '/');
    }

    /**
     * Folder inside a release that is served; "" = the release root.
     */
    public function webDir(): string
    {
        return trim($this->str('domain.web_dir'), '/');
    }

    public function ip(): ?string
    {
        return $this->nullableStr('domain.ip');
    }

    public function convertedAt(): ?string
    {
        return $this->nullableStr('domain.converted_at');
    }

    public function phpVersion(): string
    {
        return $this->str('php.version');
    }

    public function phpFamily(): string
    {
        return $this->str('php.family');
    }

    public function syncMultiPhp(): bool
    {
        return $this->get('php.sync_multiphp') === true;
    }

    public function nodeVersion(): string
    {
        return $this->str('node.version');
    }

    public function packageManager(): string
    {
        return $this->str('node.package_manager');
    }

    public function buildScript(): string
    {
        return $this->str('node.build_script');
    }

    /**
     * @return list<string>
     */
    public function expectFiles(): array
    {
        return $this->list('node.expect_files');
    }

    /**
     * @return list<string>
     */
    public function buildOutputs(): array
    {
        return $this->list('node.build_outputs');
    }

    public function removeNodeModules(): bool
    {
        return $this->get('node.remove_node_modules') !== false;
    }

    public function maxOldSpaceMb(): ?int
    {
        $v = $this->get('node.max_old_space_mb');

        return is_int($v) ? $v : null;
    }

    public function composerVersion(): string
    {
        return $this->str('composer.version');
    }

    /**
     * @return list<string>
     */
    public function composerInstallFlags(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->str('composer.install_flags'))) ?: [], static fn (string $f): bool => $f !== ''));
    }

    public function allowNoLock(): bool
    {
        return $this->get('composer.allow_no_lock') === true;
    }

    public function step(string $step): string
    {
        return $this->str('steps.' . $step);
    }

    /**
     * @return list<CustomCommand>
     */
    public function customCommands(?string $phase = null): array
    {
        $out = [];
        $list = $this->get('custom_commands', []);
        foreach (is_array($list) ? array_values($list) : [] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $command = new CustomCommand(
                $i,
                is_string($row['name'] ?? null) ? $row['name'] : 'Command ' . ($i + 1),
                is_string($row['run'] ?? null) ? $row['run'] : '',
                is_string($row['phase'] ?? null) ? $row['phase'] : CustomCommand::BEFORE,
                is_string($row['when'] ?? null) ? $row['when'] : 'every',
                is_int($row['timeout'] ?? null) ? $row['timeout'] : null,
                is_string($row['on_error'] ?? null) ? $row['on_error'] : 'fail',
            );
            if ($phase === null || $command->phase === $phase) {
                $out[] = $command;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function sharedFiles(): array
    {
        return $this->list('shared.files');
    }

    /**
     * @return list<string>
     */
    public function sharedDirs(): array
    {
        return $this->list('shared.dirs');
    }

    /**
     * @return list<string>
     */
    public function docrootFiles(): array
    {
        return $this->list('shared.docroot_files');
    }

    /**
     * @return list<string>
     */
    public function docrootDirs(): array
    {
        return $this->list('shared.docroot_dirs');
    }

    public function keepReleases(): int
    {
        $v = $this->get('releases.keep');

        return is_int($v) ? $v : 5;
    }

    public function maintenanceOptions(): string
    {
        return $this->str('maintenance.options');
    }

    public function maintenanceSecret(): ?string
    {
        return $this->nullableStr('maintenance.secret');
    }

    public function healthEnabled(): bool
    {
        return $this->get('health_check.enabled') === true;
    }

    public function healthPath(): string
    {
        return $this->str('health_check.path');
    }

    public function healthExpect(): string
    {
        return $this->str('health_check.expect');
    }

    public function healthTimeout(): int
    {
        $v = $this->get('health_check.timeout');

        return is_int($v) ? $v : 20;
    }

    public function healthAttempts(): int
    {
        $v = $this->get('health_check.attempts');

        return is_int($v) ? $v : 3;
    }

    public function healthOnFailure(): string
    {
        return $this->str('health_check.on_failure');
    }

    private function str(string $path): string
    {
        $v = $this->get($path);

        return is_scalar($v) ? (string) $v : '';
    }

    private function nullableStr(string $path): ?string
    {
        $v = $this->get($path);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return list<string>
     */
    private function list(string $path): array
    {
        $v = $this->get($path);
        if (!is_array($v)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $i): string => trim(is_scalar($i) ? (string) $i : '', '/'), $v));
    }
}
