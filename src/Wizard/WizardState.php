<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\ProjectInfo;

/**
 * Every answer of the add-site wizard (§9.3). Nothing is written until Create
 * (WIZ-01); `add --from` fills the same object from a file.
 */
final class WizardState
{
    public const ENV_EXAMPLE = 'example';
    public const ENV_PASTE = 'paste';
    public const ENV_FILE = 'file';
    public const ENV_IMPORT = 'import';
    public const ENV_LATER = 'later';

    public const DB_CREATE = 'create';
    public const DB_EXISTING = 'existing';
    public const DB_SQLITE = 'sqlite';
    public const DB_NONE = 'none';

    // Step 1
    public ?RepoUrl $repo = null;
    public ?string $branch = null;
    public string $name = '';
    /** The cpanel-git-setup.sh site this one is imported from (§10.6). */
    public ?LegacySite $legacy = null;

    // Step 2
    public string $transport = RepoUrl::TRANSPORT_SSH22;
    public ?int $keyId = null;
    /** The temporary bare clone (WIZ-01), moved to repo.git at Create. */
    public ?string $mirror = null;
    public ?CommitFiles $files = null;

    // Step 3
    public ?string $type = null;
    public ?ProjectInfo $info = null;

    // Step 4
    public ?string $domain = null;
    /** config.yml sites_dir: new sites go in ~/<sites_dir>/<domain> (LAY-04). */
    public string $sitesDir = 'cpdeploy_sites';
    public ?string $docroot = null;
    public ?string $ip = null;
    /** An existing app whose .env and storage/ are copied at Create. */
    public ?string $importFrom = null;

    // Step 5
    public ?string $webDir = null;

    // Step 6
    public ?string $phpVersion = null;
    public string $phpFamily = 'ea';
    public bool $syncMultiPhp = true;

    // Step 7
    public string $nodeVersion = 'auto';
    public string $packageManager = 'auto';
    public string $buildScript = 'build';

    // Step 8
    public string $envMode = self::ENV_EXAMPLE;
    /** Pasted .env, or the path of ENV_FILE. */
    public ?string $envContent = null;
    public string $appName = '';
    public string $appUrl = '';
    public string $dbMode = self::DB_NONE;
    public ?string $dbName = null;
    public ?string $dbUser = null;
    /** Existing database only; goes into .env, never into site.yml. */
    public ?string $dbPassword = null;

    /**
     * Settings → Defaults for new sites (§9.6), applied before any answer.
     *
     * @var array<string, mixed>
     */
    public array $defaults = [];

    // Step 9: site.yml paths changed in the steps editor.
    /** @var array<string, mixed> */
    public array $overrides = [];

    /**
     * The site.yml mapping the answers describe (before defaults and presets).
     *
     * @return array<string, mixed>
     */
    public function siteData(string $createdAt): array
    {
        $data = [
            'schema' => SiteSchema::VERSION,
            'name' => $this->name,
            'type' => $this->type ?? 'laravel',
            'created_at' => $createdAt,
            'site_dir' => $this->siteDir(),
            'repo' => [
                'owner' => $this->repo->owner ?? '',
                'name' => $this->repo->name ?? '',
                'branch' => $this->branch ?? 'main',
                'transport' => $this->transport,
                'deploy_key_id' => $this->keyId,
            ],
            'domain' => [
                'name' => (string) $this->domain,
                'docroot' => (string) $this->docroot,
                'web_dir' => $this->webDir ?? 'public',
                'ip' => $this->ip,
            ],
            'php' => [
                'version' => (string) $this->phpVersion,
                'family' => $this->phpFamily,
                'sync_multiphp' => $this->syncMultiPhp,
            ],
            'node' => [
                'version' => $this->nodeVersion,
                'package_manager' => $this->packageManager,
                'build_script' => $this->buildScript,
            ],
        ];
        if ($this->dbMode === self::DB_CREATE || $this->dbMode === self::DB_EXISTING) {
            $data['database'] = [
                'created_by_cpdeploy' => $this->dbMode === self::DB_CREATE,
                'name' => $this->dbName,
                'user' => $this->dbUser,
            ];
        }
        foreach ([...$this->defaults, ...$this->overrides] as $path => $value) {
            $data = self::setPath($data, $path, $value);
        }

        return $data;
    }

    /**
     * Settings → Defaults for new sites: keep releases, the health check and
     * MultiPHP sync.
     */
    public function applyDefaults(GlobalConfig $config): void
    {
        $this->defaults = [
            'releases.keep' => $config->defaultKeepReleases(),
            'health_check.enabled' => $config->defaultFlag('health_check'),
        ];
        $this->syncMultiPhp = $config->defaultFlag('sync_multiphp');
        $this->sitesDir = $config->sitesDir();
    }

    /**
     * The new site's folder relative to home: <sites_dir>/<domain> (LAY-04), or
     * '' while no domain is chosen.
     */
    public function siteDir(): string
    {
        return $this->domain === null || $this->domain === '' ? '' : trim($this->sitesDir, '/') . '/' . strtolower($this->domain);
    }

    /**
     * The site as it would be saved (defaults ← preset ← answers).
     *
     * @param array<string, mixed> $preset
     */
    public function config(array $preset, string $createdAt = ''): SiteConfig
    {
        return new SiteConfig(SiteSchema::withDefaults($this->siteData($createdAt), $preset));
    }

    /**
     * WIZ-02: a new repository or branch means steps 3–9 are asked again.
     */
    public function resetFromType(): void
    {
        $this->type = null;
        $this->info = null;
        $this->resetFromDomain();
        $this->webDir = null;
        $this->phpVersion = null;
        $this->nodeVersion = 'auto';
        $this->packageManager = 'auto';
        $this->buildScript = 'build';
        $this->overrides = [];
    }

    /**
     * WIZ-02: a new domain means the import and .env answers are asked again.
     */
    public function resetFromDomain(): void
    {
        $this->domain = null;
        $this->docroot = null;
        $this->ip = null;
        $this->importFrom = null;
        $this->envMode = self::ENV_EXAMPLE;
        $this->envContent = null;
        $this->appUrl = '';
        $this->dbMode = self::DB_NONE;
        $this->dbName = null;
        $this->dbUser = null;
        $this->dbPassword = null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function setPath(array $data, string $path, mixed $value): array
    {
        $ref = &$data;
        foreach (explode('.', $path) as $part) {
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

        return $data;
    }
}
