<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Config\Schema\SiteSchema;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * resources/presets/<type>.yml: partial site.yml values per project type (§8.4),
 * merged under the user's own values.
 */
final class Presets
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly string $dir = '')
    {
    }

    public static function defaultDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/presets';
    }

    /**
     * @return array<string, mixed>
     */
    public function for(string $type): array
    {
        if (!in_array($type, SiteSchema::TYPES, true)) {
            return [];
        }
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }
        $file = ($this->dir !== '' ? $this->dir : self::defaultDir()) . '/' . $type . '.yml';
        if (!is_file($file)) {
            throw new RuntimeException("Preset file missing: {$file}");
        }
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
            throw new RuntimeException("Preset file is not a mapping: {$file}");
        }
        /** @var array<string, mixed> $data */
        return $this->cache[$type] = $data;
    }
}
