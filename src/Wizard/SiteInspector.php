<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Project\ProjectDetector;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Runtime\ComposerInstaller;
use Cpdeploy\Runtime\DomainPhp;
use Cpdeploy\Runtime\NodeInstaller;
use Cpdeploy\Runtime\NodeLocator;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Runtime\NodeVersion;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Runtime\PhpLocator;
use Cpdeploy\Runtime\PhpService;
use Cpdeploy\Ui\Reporter;
use Throwable;

/**
 * Read-only analysis for wizard steps 3–7 (§9.3): the project type, the
 * domains and the state of their folders, the served-folder candidates, PHP
 * compatibility (PHP-05), the Node options (NODE-01, NODE-04), and an existing
 * app to import.
 */
final class SiteInspector
{
    public function __construct(
        private readonly SiteRegistry $sites,
        private readonly Presets $presets,
        private readonly DomainService $domains,
        private readonly DocrootManager $docroots,
        private readonly ProjectDetector $detector,
        private readonly PhpLocator $locator,
        private readonly PhpService $php,
        private readonly ComposerInstaller $composer,
        private readonly ComposerInspector $inspector,
        private readonly NodeLocator $nodes,
        private readonly NodeResolver $resolver,
        private readonly NodeInstaller $installer,
    ) {
    }

    public function detect(CommitFiles $files): ProjectInfo
    {
        return $this->detector->detect($files);
    }

    public function defaultWebDir(string $type, CommitFiles $files): string
    {
        return $this->detector->defaultWebDir($type, $files);
    }

    /**
     * Step 4: every domain except parked ones, with the state of its folder and,
     * when it can't be used, why (DOC-01).
     *
     * @return list<DomainRow>
     */
    public function domainRows(WizardState $state): array
    {
        $all = $this->domains->all(true);
        $rows = [];
        foreach ($all as $domain) {
            if (!$domain->selectable()) {
                continue;
            }
            $rows[] = $this->row($state, $domain, $domain->documentRoot, $all);
        }

        return $rows;
    }

    /**
     * One domain with a given folder (also for *Other folder…*).
     *
     * @param list<Domain> $all
     */
    public function row(WizardState $state, Domain $domain, string $docroot, array $all): DomainRow
    {
        foreach ($this->sites->names() as $site) {
            try {
                if ($this->sites->load($site)->docroot() === $docroot) {
                    return new DomainRow($domain, $docroot, "used by site \"{$site}\"", false, "{$docroot} is already managed by the site {$site}.");
                }
            } catch (Throwable) {
                continue;
            }
        }
        $probe = clone $state;
        $probe->domain = $domain->name;
        $probe->docroot = $docroot;
        $probe->ip = $domain->ip;
        $config = $probe->config($this->presets->for($state->type ?? 'laravel'));
        $problems = $this->docroots->problems($config, $all);
        // (g) only applies to the domain's own document root; *Other folder…* is allowed to differ.
        $problems = array_values(array_filter($problems, static fn (string $p): bool => !str_contains($p, 'but cpdeploy manages')));
        if ($problems !== []) {
            return new DomainRow($domain, $docroot, 'unavailable: ' . self::short($problems[0]), false, $problems[0]);
        }

        return new DomainRow($domain, $docroot, self::folderState($docroot), true, null, $this->existingApp($docroot));
    }

    /**
     * "empty", "N items", "app found", or "symlink".
     */
    public static function folderState(string $docroot): string
    {
        if (is_link($docroot)) {
            return 'symlink';
        }
        if (!is_dir($docroot)) {
            return 'empty';
        }
        if (is_file($docroot . '/artisan') && is_file($docroot . '/.env')) {
            return 'app found';
        }
        $count = count(array_diff(scandir($docroot) ?: [], ['.', '..']));

        return $count === 0 ? 'empty' : $count . ' item' . ($count === 1 ? '' : 's');
    }

    /**
     * An existing Laravel app (artisan + .env) in the docroot or its parent.
     */
    public function existingApp(string $docroot): ?string
    {
        foreach ([$docroot, dirname($docroot)] as $dir) {
            if (!is_link($dir) && is_file($dir . '/artisan') && is_file($dir . '/.env')) {
                return $dir;
            }
        }

        return null;
    }

    public function domainPhp(?string $name): ?DomainPhp
    {
        if ($name === null) {
            return null;
        }
        try {
            $domain = $this->domains->find($name);

            return $domain !== null ? $this->php->domainPhp($domain) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Step 6: every installed PHP with its PHP-05 problems ([] = compatible,
     * null = unknown), newest first.
     *
     * @return list<array{0: PhpInstall, 1: list<string>|null}>
     */
    public function phpOptions(ProjectInfo $info, CommitFiles $files, string $composerVersion, Reporter $reporter): array
    {
        $phar = '';
        if ($info->hasComposer() && $info->hasLock()) {
            try {
                $phar = $this->composer->ensure($composerVersion, $reporter);
            } catch (Throwable) {
                $phar = null;
            }
        }
        $out = [];
        foreach ($this->locator->installs() as $install) {
            try {
                $out[] = [$install, $phar === null ? null : $this->inspector->platformProblems($info, $files, $install, $phar)];
            } catch (Throwable) {
                $out[] = [$install, null];
            }
        }

        return $out;
    }

    /**
     * The step 6 default: the domain's current version when compatible, else the
     * highest compatible one, else the newest installed.
     *
     * @param list<array{0: PhpInstall, 1: list<string>|null}> $options
     */
    public static function defaultPhp(array $options, ?DomainPhp $domain): ?PhpInstall
    {
        foreach ($options as [$install, $problems]) {
            if ($domain !== null && $install->tag() === $domain->tag() && $problems === []) {
                return $install;
            }
        }
        foreach ($options as [$install, $problems]) {
            if ($problems === []) {
                return $install;
            }
        }

        return $options[0][0] ?? null;
    }

    /**
     * Step 7 (NODE-01, NODE-04): the repo's spec, installed versions that satisfy
     * it, and the best downloadable version (null when none, or offline).
     *
     * @return array{spec: ?NodeSpec, installed: list<NodeVersion>, download: ?string}
     */
    public function nodeOptions(CommitFiles $files): array
    {
        $spec = NodeResolver::specFromProject(null, $files->reader());
        $installed = array_values(array_filter($this->nodes->installed(), static fn (NodeVersion $n): bool => $spec === null || $spec->kind === NodeSpec::LTS || $spec->matches($n->version)));
        $download = null;
        try {
            $download = $this->resolver->fromIndex($spec ?? NodeSpec::parse('lts/*', 'default'), $this->installer->arch());
        } catch (Throwable) {
            $download = null;
        }

        return ['spec' => $spec, 'installed' => $installed, 'download' => $download];
    }

    private static function short(string $problem): string
    {
        return mb_strlen($problem) > 40 ? mb_substr($problem, 0, 39) . '…' : $problem;
    }
}
