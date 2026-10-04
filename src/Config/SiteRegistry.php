<?php

declare(strict_types=1);

namespace Cpdeploy\Config;

use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lists, loads (migrate → defaults → validate) and saves sites (§8.2, CFG-01…03).
 */
final class SiteRegistry
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Presets $presets,
    ) {
    }

    /**
     * Site names with a site.yml, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->paths->siteNames();
    }

    public function exists(string $name): bool
    {
        return preg_match(SiteSchema::NAME_PATTERN, $name) === 1 && is_file($this->paths->siteConfig($name));
    }

    public function load(string $name): SiteConfig
    {
        if (!$this->exists($name)) {
            $known = $this->names();
            throw new CpdeployException(
                ErrorCode::USAGE,
                "There is no site named '{$name}'",
                $known === [] ? 'No sites are set up yet.' : 'Sites: ' . implode(', ', $known),
            );
        }
        $file = $this->paths->siteConfig($name);
        @chmod($file, Paths::MODE_SECRET_FILE);
        $config = $this->parse((string) file_get_contents($file), $file, true);
        if ($config->name() !== $name) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                sprintf("%s has problems:\n  - name: '%s' doesn't match the folder name '%s'", $file, $config->name(), $name),
                "The name can't change after creation. Run: cpdeploy config {$name} edit",
            );
        }
        $this->paths->useSiteDir($name, $config->siteDir());

        return $config;
    }

    /**
     * Parses and validates site.yml text. $migrateFile: write a migrated schema
     * back to $file (after a .schemaN.bak backup, CFG-02).
     */
    public function parse(string $raw, string $file, bool $migrateFile = false): SiteConfig
    {
        $site = basename(dirname($file));
        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, sprintf('%s has problems: %s', $file, $e->getMessage()), "Run: cpdeploy config {$site} edit");
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} has problems: it is not a YAML mapping", "Run: cpdeploy config {$site} edit");
        }
        /** @var array<string, mixed> $data */
        $schema = $data['schema'] ?? SiteSchema::VERSION;
        if (!is_int($schema) || $schema < 1) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} has problems: schema must be a positive whole number", 'Set schema: ' . SiteSchema::VERSION);
        }
        if ($schema > SiteSchema::VERSION) {
            throw new CpdeployException(ErrorCode::CONFIG_NEWER, "{$file} was written by a newer cpdeploy", 'Run: cpdeploy self-update');
        }
        $migrated = SiteSchema::needsMigration($schema);
        if ($migrated) {
            if ($migrateFile) {
                $this->fs->writeAtomic($file . '.schema' . $schema . '.bak', $raw, Paths::MODE_SECRET_FILE);
            }
            $data = SiteSchema::migrate($data, $schema);
        }

        if (!array_key_exists('site_dir', $data)) {
            // LAY-04: rc.1 and rc.2 kept the site's files in ~/cpdeploy/sites/<site>; there is no migration.
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                "{$file} was made by cpdeploy 1.0.0-rc.1 or rc.2, which kept the site's files in ~/cpdeploy/sites/{$site}. "
                . 'This version keeps them in their own folder (~/cpdeploy_sites/<domain>).',
                "Remove the site with cpdeploy 1.0.0-rc.2 (cpdeploy remove {$site}), then add it again with this version.",
            );
        }

        $type = is_string($data['type'] ?? null) ? $data['type'] : 'laravel';
        $merged = SiteSchema::withDefaults($data, $this->presets->for($type));
        $result = SiteSchema::validate($merged);
        if ($result['errors'] !== []) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                sprintf("%s has problems:\n  - %s", $file, implode("\n  - ", $result['errors'])),
                "Run: cpdeploy config {$site} edit",
            );
        }
        $config = new SiteConfig($merged, $result['warnings']);
        if ($migrated && $migrateFile) {
            $this->save($config);
        }

        return $config;
    }

    /**
     * Validates (schema + DOC-01 a, b, c, e) and writes site.yml atomically, mode 600.
     */
    public function save(SiteConfig $config): void
    {
        $result = SiteSchema::validate($config->toArray());
        $errors = [...$result['errors'], ...$this->docrootProblems($config)];
        $file = $this->paths->siteConfig($config->name());
        if ($errors !== []) {
            throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                sprintf("%s has problems:\n  - %s", $file, implode("\n  - ", $errors)),
                'Nothing was saved. Fix the values and try again.',
            );
        }
        $this->fs->ensureDir($this->paths->siteDir($config->name()), Paths::MODE_ROOT);
        $this->fs->writeAtomic($file, self::dump($config), Paths::MODE_SECRET_FILE);
        $this->paths->useSiteDir($config->name(), $config->siteDir());
    }

    public static function dump(SiteConfig $config): string
    {
        return GlobalConfig::HEADER . "\n" . Yaml::dump($config->toArray(), 4, 2, Yaml::DUMP_NULL_AS_TILDE);
    }

    /**
     * DOC-01 rules that only need the paths and the other sites (VAL-03):
     * (a) inside $HOME and not $HOME; (b) not inside ~/cpdeploy or the site's
     * own folder (site_dir), and not containing them; (c) its parent's real path
     * isn't inside another managed site (either of its folders);
     * (e) no other site uses the same docroot.
     *
     * @return list<string>
     */
    public function docrootProblems(SiteConfig $config): array
    {
        $docroot = $config->docroot();
        if ($docroot === '' || !str_starts_with($docroot, '/')) {
            return [];
        }
        $problems = [];
        $home = Fs::normalize($this->paths->home());
        $root = Fs::normalize($this->paths->root());
        $normal = Fs::normalize($docroot);

        if ($normal === $home || !Fs::isInside($normal, $home)) {
            $problems[] = "domain.docroot: {$docroot} must be a folder inside your home folder, not the home folder itself";
        }
        if ($normal === $root || Fs::isInside($normal, $root) || Fs::isInside($root, $normal)) {
            $problems[] = "domain.docroot: {$docroot} can't be inside ~/cpdeploy or contain it";
        }
        if ($config->siteDir() !== '') {
            $siteFiles = Fs::normalize($this->paths->fromHome($config->siteDir()));
            if ($normal === $siteFiles || Fs::isInside($normal, $siteFiles) || Fs::isInside($siteFiles, $normal)) {
                $problems[] = "domain.docroot: {$docroot} can't be inside the site's folder ~/{$config->siteDir()} or contain it";
            }
        }

        $parent = realpath(dirname($normal));
        foreach ($this->names() as $other) {
            if ($other === $config->name()) {
                continue;
            }
            try {
                $otherConfig = $this->load($other);
            } catch (CpdeployException) {
                $otherConfig = null;
            }
            $otherDirs = [$this->paths->siteDir($other)];
            if ($otherConfig !== null && $otherConfig->siteDir() !== '') {
                $otherDirs[] = $this->paths->fromHome($otherConfig->siteDir());
            }
            foreach ($otherDirs as $otherDir) {
                $realOther = realpath($otherDir) ?: $otherDir;
                if ($parent !== false && ($parent === $realOther || Fs::isInside($parent, $realOther))) {
                    $problems[] = "domain.docroot: this domain's folder lives inside another managed site ({$other}). Change its document root in cPanel → Domains to a folder outside it, e.g. ~/" . $config->domain();
                    break;
                }
            }
            if ($otherConfig === null) {
                continue;
            }
            if (Fs::normalize($otherConfig->docroot()) === $normal) {
                $problems[] = "domain.docroot: {$docroot} is already used by the site {$other}";
            }
        }

        return $problems;
    }
}
