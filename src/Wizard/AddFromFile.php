<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Reporter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * `cpdeploy add --from=<file.yml>` (§9.3): the wizard's answers from a
 * site.yml plus a `setup:` section, without questions. Uses the same services
 * as the wizard (ARC-03). Without a token the deploy key is printed and the
 * command stops (exit 2); running it again reuses the key and continues.
 */
final class AddFromFile
{
    /** The site.yml paths the wizard state models itself; everything else is kept as written. */
    private const MODELLED = [
        'schema', 'name', 'type', 'created_at',
        'repo.owner', 'repo.name', 'repo.branch', 'repo.transport', 'repo.deploy_key_id', 'repo.url',
        'domain.name', 'domain.docroot', 'domain.web_dir', 'domain.ip',
        'php.version', 'php.family', 'php.sync_multiphp',
        'node.version', 'node.package_manager', 'node.build_script',
        'database.created_by_cpdeploy', 'database.name', 'database.user',
    ];

    public const SETUP_KEYS = ['env', 'app_name', 'app_url', 'database', 'db_existing', 'deploy_now'];

    public function __construct(
        private readonly RepoAccess $access,
        private readonly SiteInspector $inspector,
        private readonly SiteCreator $creator,
        private readonly DomainService $domains,
        private readonly SiteRegistry $sites,
        private readonly Presets $presets,
        private readonly Environment $environment,
    ) {
    }

    public function run(string $file, Reporter $reporter): AddFromFileResult
    {
        [$data, $setup] = self::read($file);
        $state = new WizardState();
        $tx = new WizardTransaction();
        $this->fill($state, $data, $setup, dirname($file));
        $repo = $state->repo;
        if ($repo === null) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file}: repo.owner and repo.name are required", 'Example: repo: {owner: acme, name: shop, branch: main}');
        }

        try {
            // Step 2: transport, key, access (GIT-04, GIT-05, GIT-17, GIT-06).
            if (!isset($data['repo']['transport'])) {
                try {
                    $state->transport = $this->access->detectTransport();
                } catch (CpdeployException) {
                    $state->transport = RepoUrl::TRANSPORT_SSH22;
                }
            }
            $existed = is_file($this->access->keyPath($state->name));
            $manual = $this->access->prepareKey($repo, $state->name, $state, $tx, $this->access->api());
            if (!$existed) {
                $reporter->info('Created deploy key ' . $this->access->keyPath($state->name));
            }
            if ($manual !== null && !$existed) {
                return AddFromFileResult::keyNeeded($manual->lines($repo->fullName()));
            }
            try {
                $branches = $this->access->test($repo, $state->name, $state->transport);
            } catch (CpdeployException $e) {
                if ($manual === null || !in_array($e->errorCode, [ErrorCode::GIT_AUTH], true)) {
                    throw $e;
                }

                return AddFromFileResult::keyNeeded([$e->getMessage(), ...$manual->lines($repo->fullName())]);
            }
            $reporter->info('Access OK');
            if ($state->branch === null) {
                $state->branch = $this->access->defaultBranch($repo, $state->name, $state->transport) ?? ($branches[0] ?? null);
                if ($state->branch === null) {
                    throw new CpdeployException(ErrorCode::REF_NOT_FOUND, "{$repo->fullName()} has no branches yet", 'Push a commit first.');
                }
            } elseif ($branches !== [] && !in_array($state->branch, $branches, true)) {
                throw new CpdeployException(ErrorCode::REF_NOT_FOUND, "The branch {$state->branch} doesn't exist in {$repo->fullName()}", 'Branches: ' . implode(', ', array_slice($branches, 0, 10)));
            }

            $reporter->start('Downloading repository…');
            $state->mirror = $this->access->cloneTemporary($repo, $state->name, $state->transport, $tx);
            $reporter->succeed($repo->fullName());

            // Steps 3–7: what the file leaves out is detected, as the wizard would propose.
            $state->files = $this->access->files($state->mirror, $repo, $state->branch);
            $state->info = $this->inspector->detect($state->files);
            $state->type ??= $state->info->detectedType;
            $state->webDir ??= $this->inspector->defaultWebDir($state->type, $state->files);
            if ($state->phpVersion === null) {
                $composer = $state->config($this->presets->for($state->type))->composerVersion();
                $options = $this->inspector->phpOptions($state->info, $state->files, $composer, $reporter);
                $php = SiteInspector::defaultPhp($options, $this->inspector->domainPhp($state->domain));
                if ($php === null) {
                    throw new CpdeployException(ErrorCode::PHP_MISSING, 'No PHP installation was found on this server', 'Set php.version in the file.', exitCode: 3);
                }
                $state->phpVersion = $php->majorMinor();
                $state->phpFamily = $php->family;
            }
            if ($state->type !== 'laravel') {
                $state->dbMode = WizardState::DB_NONE;
            }

            $config = $state->config($this->presets->for($state->type));
            $errors = SiteSchema::validate($config->toArray())['errors'];
            if ($errors !== []) {
                throw new CpdeployException(ErrorCode::CONFIG_INVALID, sprintf("%s has problems:\n  - %s", $file, implode("\n  - ", $errors)), 'Nothing was created. Fix the values and run the command again.');
            }

            // Step 10: Create (WIZ-04).
            $config = $this->creator->create($state, $tx, $reporter);

            return AddFromFileResult::created($config, $state, (bool) ($setup['deploy_now'] ?? false));
        } finally {
            $this->access->discard($tx, $repo, $state->name, false);
        }
    }

    /**
     * The site.yml mapping and its `setup:` section.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            throw new CpdeployException(ErrorCode::USAGE, "{$file} doesn't exist", 'Pass the path of a site.yml with a setup: section.');
        }
        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException $e) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} isn't valid YAML: " . $e->getMessage(), 'Fix the file and run the command again.');
        }
        if (!is_array($data)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file} is empty or not a mapping", 'Start from a site.yml (§8.2) with a setup: section.');
        }
        /** @var array<string, mixed> $data */
        $setup = is_array($data['setup'] ?? null) ? $data['setup'] : [];
        unset($data['setup']);
        foreach (array_keys($setup) as $key) {
            if (!in_array($key, self::SETUP_KEYS, true)) {
                throw new CpdeployException(ErrorCode::CONFIG_INVALID, "{$file}: unknown setup key '{$key}'", 'Allowed: ' . implode(', ', self::SETUP_KEYS));
            }
        }

        /** @var array<string, mixed> $setup */
        return [$data, $setup];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $setup
     */
    private function fill(WizardState $state, array $data, array $setup, string $baseDir): void
    {
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        if (preg_match(SiteSchema::NAME_PATTERN, $name) !== 1) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "name: '{$name}' must be 1–31 characters of a–z, 0–9 and \"-\", starting with a letter or digit", 'Set name: in the file.');
        }
        if (in_array($name, SiteSchema::RESERVED, true)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "name: '{$name}' is reserved (it's a cpdeploy command)", 'Choose another name.');
        }
        if ($this->sites->exists($name)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "A site named {$name} already exists", 'Choose another name, or remove the site first.');
        }
        $state->name = $name;

        $repo = is_array($data['repo'] ?? null) ? $data['repo'] : [];
        $address = is_string($repo['url'] ?? null) ? $repo['url'] : (is_string($repo['owner'] ?? null) && is_string($repo['name'] ?? null) ? $repo['owner'] . '/' . $repo['name'] : null);
        $state->repo = $address !== null ? RepoUrl::parse($address) : null;
        $state->branch = is_string($repo['branch'] ?? null) && $repo['branch'] !== '' ? $repo['branch'] : null;
        if (is_string($repo['transport'] ?? null)) {
            $state->transport = $repo['transport'];
        }

        $type = $data['type'] ?? null;
        if ($type !== null && !in_array($type, SiteSchema::TYPES, true)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, 'type: must be one of ' . implode(', ', SiteSchema::TYPES), 'Or leave it out to detect it.');
        }
        $state->type = is_string($type) ? $type : null;

        $domain = is_array($data['domain'] ?? null) ? $data['domain'] : [];
        $domainName = is_string($domain['name'] ?? null) ? strtolower($domain['name']) : '';
        $found = $domainName !== '' ? $this->domains->find($domainName) : null;
        if ($found === null || !$found->selectable()) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "domain.name: '{$domainName}' isn't a domain of this cPanel account", 'Use a main, addon or subdomain listed in cPanel → Domains.');
        }
        $state->domain = $found->name;
        $state->docroot = is_string($domain['docroot'] ?? null) && $domain['docroot'] !== '' ? $domain['docroot'] : $found->documentRoot;
        $state->ip = is_string($domain['ip'] ?? null) ? $domain['ip'] : $found->ip;
        $state->webDir = is_string($domain['web_dir'] ?? null) ? $domain['web_dir'] : null;

        $php = is_array($data['php'] ?? null) ? $data['php'] : [];
        $state->phpVersion = is_scalar($php['version'] ?? null) && (string) $php['version'] !== '' ? (string) $php['version'] : null;
        $state->phpFamily = is_string($php['family'] ?? null) ? $php['family'] : 'ea';
        $state->syncMultiPhp = !isset($php['sync_multiphp']) || $php['sync_multiphp'] === true;

        $node = is_array($data['node'] ?? null) ? $data['node'] : [];
        $state->nodeVersion = is_scalar($node['version'] ?? null) ? (string) $node['version'] : 'auto';
        $state->packageManager = is_string($node['package_manager'] ?? null) ? $node['package_manager'] : 'auto';
        $state->buildScript = is_string($node['build_script'] ?? null) ? $node['build_script'] : 'build';

        // Every other key is kept exactly as written.
        foreach (self::flatten($data) as $path => $value) {
            if (!in_array($path, self::MODELLED, true)) {
                $state->overrides[$path] = $value;
            }
        }

        // setup: env
        $env = is_string($setup['env'] ?? null) ? $setup['env'] : 'example';
        if ($env === 'example') {
            $state->envMode = WizardState::ENV_EXAMPLE;
        } elseif ($env === 'none') {
            $state->envMode = WizardState::ENV_LATER;
        } elseif (str_starts_with($env, 'file:')) {
            $path = substr($env, 5);
            $path = str_starts_with($path, '/') ? $path : $baseDir . '/' . $path;
            if (!is_file($path)) {
                throw new CpdeployException(ErrorCode::USAGE, "setup.env: {$path} doesn't exist", 'Use env: example, env: none, or env: file:<path of a .env>.');
            }
            $state->envMode = WizardState::ENV_FILE;
            $state->envContent = $path;
        } else {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "setup.env: '{$env}' isn't allowed", 'Use example, none or file:<path>.');
        }
        $state->appName = is_string($setup['app_name'] ?? null) ? $setup['app_name'] : '';
        $state->appUrl = is_string($setup['app_url'] ?? null) ? $setup['app_url'] : 'https://' . $state->domain;

        // setup: database
        $database = is_string($setup['database'] ?? null) ? $setup['database'] : WizardState::DB_NONE;
        if (!in_array($database, [WizardState::DB_CREATE, WizardState::DB_EXISTING, WizardState::DB_SQLITE, WizardState::DB_NONE], true)) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, "setup.database: '{$database}' isn't allowed", 'Use create, existing, sqlite or none.');
        }
        if ($state->envMode === WizardState::ENV_LATER && $database !== WizardState::DB_NONE) {
            throw new CpdeployException(ErrorCode::CONFIG_INVALID, 'setup.database needs a .env to write to', 'Use env: example or env: file:<path>, or database: none.');
        }
        $state->dbMode = $database;
        if ($database === WizardState::DB_EXISTING) {
            $existing = is_array($setup['db_existing'] ?? null) ? $setup['db_existing'] : [];
            $variable = is_string($existing['password_env'] ?? null) ? $existing['password_env'] : '';
            $password = $variable !== '' ? $this->environment->get($variable) : null;
            if (!is_string($existing['name'] ?? null) || !is_string($existing['user'] ?? null) || $variable === '') {
                throw new CpdeployException(ErrorCode::CONFIG_INVALID, 'setup.db_existing needs name, user and password_env', 'The password is read from the environment variable password_env names, never from the file.');
            }
            if ($password === null) {
                throw new CpdeployException(ErrorCode::USAGE, "The environment variable {$variable} (setup.db_existing.password_env) isn't set", "Run: {$variable}='…' cpdeploy add --from=…");
            }
            $state->dbName = $existing['name'];
            $state->dbUser = $existing['user'];
            $state->dbPassword = $password;
        }
    }

    /**
     * Nested mappings as dotted paths; lists and scalars are leaves.
     *
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $out += self::flatten($value, $path . '.');
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
