<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Deploy\Release;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Deploy\StateFile;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Git\DeployKeyService;
use Cpdeploy\Laravel\Maintenance;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\PhpChange;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Support\Fs;
use Throwable;

/**
 * The per-site group of `cpdeploy check` (§9.7): deploy key access, the site
 * PHP and Node, .env, the docroot link, the live release, shared folders, an
 * interrupted operation, maintenance mode, and with --probe the PHP the domain
 * really serves (HTTP-04).
 */
final class SiteCheck
{
    public const ENV_MODE = 'site.env.mode';

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly DocrootManager $docroots,
        private readonly DeployKeyService $keys,
        private readonly PhpService $php,
        private readonly NodeResolver $nodes,
        private readonly PhpChange $phpChange,
    ) {
    }

    public function run(string $site, bool $probe = false): CheckGroup
    {
        $name = 'Site ' . $site;
        try {
            $config = $this->sites->load($site);
        } catch (Throwable $e) {
            return new CheckGroup($name, [CheckResult::fail('site.config', $e->getMessage(), "Fix it with: cpdeploy config {$site} edit")]);
        }
        $live = $this->releases->live($site);

        $checks = [
            $this->key($config),
            $this->phpBinary($config),
        ];
        $node = $this->node($config, $live);
        if ($node !== null) {
            $checks[] = $node;
        }
        if ($config->isLaravel() || in_array('.env', $config->sharedFiles(), true)) {
            $checks[] = $this->env($config);
        }
        $checks[] = $this->docroot($config, $live);
        $checks[] = $this->current($live);
        $checks[] = $this->shared($config);
        $checks[] = $this->state($site);
        if ($config->isLaravel() && $live !== null && Maintenance::isDown($live->dir)) {
            $checks[] = CheckResult::warn('site.maintenance', 'Maintenance mode is on: visitors see the maintenance page', "Run: cpdeploy up {$site}");
        }
        if ($probe) {
            $checks[] = $this->probe($config, $live);
        }

        return new CheckGroup($name, $checks);
    }

    /**
     * Sets shared/.env to 600 (the auto-fix `check` offers).
     */
    public function fixEnvMode(string $site): void
    {
        @chmod($this->paths->sharedEnv($site), Paths::MODE_SECRET_FILE);
    }

    private function key(SiteConfig $config): CheckResult
    {
        $site = $config->name();
        if (!$this->keys->exists($site)) {
            return CheckResult::fail('site.key', "The deploy key {$this->keys->keyPath($site)} is missing", "Rotate it: cpdeploy key {$site} rotate");
        }
        try {
            $this->keys->test($config->repo(), $site, $config->transport());

            return CheckResult::ok('site.key', "Deploy key can read {$config->repo()->fullName()}");
        } catch (Throwable $e) {
            return CheckResult::fail('site.key', "The deploy key can't read {$config->repo()->fullName()}: " . $e->getMessage(), "Check it with: cpdeploy key {$site} test");
        }
    }

    private function phpBinary(SiteConfig $config): CheckResult
    {
        try {
            $php = $this->php->resolve($config->phpVersion(), $config->phpFamily());

            return CheckResult::ok('site.php', "PHP {$php->version} ({$php->tag()}) at {$php->binary}");
        } catch (Throwable $e) {
            return CheckResult::fail('site.php', $e->getMessage(), "Choose an installed version: cpdeploy php {$config->name()} <version>");
        }
    }

    private function node(SiteConfig $config, ?Release $live): ?CheckResult
    {
        if ($config->buildScript() === '' || strtolower($config->nodeVersion()) === 'none') {
            return null;
        }
        $read = static fn (string $file): ?string => $live !== null && is_file($live->dir . '/' . $file) ? (string) file_get_contents($live->dir . '/' . $file) : null;
        if ($live !== null && $read('package.json') === null) {
            return null;
        }
        try {
            $spec = NodeResolver::specFromProject($config->nodeVersion(), $read);
            $node = $this->nodes->installed($spec);
            if ($node !== null) {
                return CheckResult::ok('site.node', "Node {$node->version} at {$node->binDir}" . ($spec !== null ? ' for ' . $spec->describe() : ''));
            }

            return CheckResult::warn('site.node', 'No installed Node matches ' . ($spec?->describe() ?? 'the site'), 'It is downloaded at the next deploy (needs nodejs.org or mirrors.node)');
        } catch (Throwable $e) {
            return CheckResult::warn('site.node', $e->getMessage(), "Set a version: cpdeploy node {$config->name()} <version>");
        }
    }

    private function env(SiteConfig $config): CheckResult
    {
        $file = $this->paths->sharedEnv($config->name());
        if (!is_file($file)) {
            return CheckResult::fail('site.env', 'shared/.env is missing: deploys are blocked', "Create it: cpdeploy env {$config->name()} edit");
        }
        $mode = fileperms($file) & 0777;
        if ($mode !== Paths::MODE_SECRET_FILE) {
            return CheckResult::warn(self::ENV_MODE, sprintf('shared/.env has mode %o (should be 600: it holds secrets)', $mode), "Run: chmod 600 {$file}");
        }

        return CheckResult::ok('site.env', 'shared/.env exists (600)');
    }

    private function docroot(SiteConfig $config, ?Release $live): CheckResult
    {
        $docroot = $config->docroot();
        if ($live === null && !$this->docroots->isConverted($config)) {
            return CheckResult::info('site.docroot', "{$docroot}: not live yet (the first deploy turns it into a link)");
        }
        if (!$this->docroots->isConverted($config)) {
            return CheckResult::fail('site.docroot', "{$docroot} is not the link cpdeploy made", "See the docroot problems: cpdeploy deploy {$config->name()} explains them");
        }
        if ($this->docroots->needsRepoint($config)) {
            return CheckResult::warn('site.docroot', "{$docroot} points at " . (string) $this->docroots->symlinkTarget($docroot) . ' instead of current/' . $config->webDir(), 'The next deploy re-points it');
        }

        return CheckResult::ok('site.docroot', "{$docroot} → current" . ($config->webDir() === '' ? '' : '/' . $config->webDir()));
    }

    private function current(?Release $live): CheckResult
    {
        if ($live === null) {
            return CheckResult::info('site.current', 'No live release yet');
        }
        if ($live->status() !== Release::LIVE) {
            return CheckResult::warn('site.current', "current → {$live->id}, whose status is {$live->status()}", 'Deploy again, or roll back to a good release');
        }

        return CheckResult::ok('site.current', "current → {$live->id} ({$live->short()})");
    }

    private function shared(SiteConfig $config): CheckResult
    {
        $shared = $this->paths->sharedDir($config->name());
        $bad = [];
        foreach ($config->sharedDirs() as $dir) {
            $path = $shared . '/' . $dir;
            if (is_dir($path) && !is_writable($path)) {
                $bad[] = $dir;
            }
        }
        if ($bad !== []) {
            return CheckResult::fail('site.shared', 'Not writable: ' . implode(', ', $bad), 'Fix the permissions in cPanel → File Manager (the folders must belong to this account)');
        }

        return CheckResult::ok('site.shared', 'Shared folders writable');
    }

    private function state(string $site): CheckResult
    {
        $data = (new StateFile($this->paths->stateFile($site), $this->fs))->read();
        if ($data !== null) {
            return CheckResult::warn('site.state', sprintf('An earlier %s was interrupted (phase: %s)', (string) ($data['operation'] ?? 'operation'), (string) ($data['phase'] ?? 'unknown')), "Recover: cpdeploy recover {$site}");
        }

        return CheckResult::ok('site.state', 'No interrupted operation');
    }

    private function probe(SiteConfig $config, ?Release $live): CheckResult
    {
        if ($live === null) {
            return CheckResult::info('site.probe', 'Served PHP: not live yet');
        }
        try {
            $served = $this->phpChange->probe($config->name());
        } catch (Throwable $e) {
            return CheckResult::warn('site.probe', "Couldn't check the served PHP: " . $e->getMessage());
        }
        if ($served === null) {
            return CheckResult::warn('site.probe', "Couldn't check the served PHP (the probe got no answer)", 'The site may block unknown .php files, or not answer over HTTPS');
        }
        if (PhpInstall::majorMinorOf($served) !== $config->phpVersion()) {
            return CheckResult::fail('site.probe', "The domain serves PHP {$served}, the site builds with PHP {$config->phpVersion()}", "Set it: cpdeploy php {$config->name()} {$config->phpVersion()} --switch-now");
        }

        return CheckResult::ok('site.probe', "The domain serves PHP {$served}");
    }
}
