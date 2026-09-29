<?php

declare(strict_types=1);

namespace Cpdeploy\Project;

use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Runtime\PackageManagerChoice;

/**
 * Node facts of a commit: whether it builds, the version spec (NODE-01), the
 * package manager (NODE-07), lockfiles, expected outputs (NODE-11) and the
 * outputs reused when the build is skipped (NODE-13).
 */
final class NodeInspector
{
    public const LOCKFILES = [
        PackageManagerChoice::NPM => 'package-lock.json',
        PackageManagerChoice::PNPM => 'pnpm-lock.yaml',
        PackageManagerChoice::YARN => 'yarn.lock',
        PackageManagerChoice::YARN_BERRY => 'yarn.lock',
    ];

    /** Mix outputs (§8.4). */
    public const MIX_OUTPUTS = ['public/js', 'public/css', 'public/mix-manifest.json'];

    /**
     * Whether a frontend build can run at all for this commit: the step isn't off,
     * node.version isn't "none", and package.json has the build script.
     */
    public static function builds(SiteConfig $config, ProjectInfo $info): bool
    {
        return $config->step('frontend_build') !== 'off'
            && strtolower($config->nodeVersion()) !== 'none'
            && $info->hasScript($config->buildScript());
    }

    public function spec(SiteConfig $config, CommitFiles $files): ?NodeSpec
    {
        return NodeResolver::specFromProject($config->nodeVersion(), $files->reader());
    }

    /**
     * NODE-07, or the site's node.package_manager when it isn't auto.
     */
    public function packageManager(SiteConfig $config, CommitFiles $files): PackageManagerChoice
    {
        $package = $files->json('package.json');
        $forced = $config->packageManager();
        if ($forced === 'auto') {
            return PackageManager::detect($package, $files->checker(), $files->reader());
        }
        $declared = is_array($package) && is_string($package['packageManager'] ?? null) ? $package['packageManager'] : '';
        $version = preg_match('/^' . preg_quote($forced, '/') . '@(\d+[^+\s]*)/', $declared, $m) === 1 ? $m[1] : null;
        if ($forced === 'npm') {
            return new PackageManagerChoice(PackageManagerChoice::NPM, $version, 'site.yml (node.package_manager)');
        }
        // pnpm / yarn: detect as if the matching lockfile were present, so Yarn Berry rules apply.
        $fake = $version !== null ? ['packageManager' => $forced . '@' . $version] : null;
        $lock = self::LOCKFILES[$forced];

        return PackageManager::detect(
            $fake,
            fn (string $path): bool => $path === $lock || ($path !== 'package-lock.json' && $path !== 'bun.lock' && $path !== 'bun.lockb' && $files->exists($path)),
            $files->reader(),
        );
    }

    public function hasLockfile(PackageManagerChoice $pm, CommitFiles $files): bool
    {
        return $files->exists(self::LOCKFILES[$pm->name] ?? 'package-lock.json');
    }

    /**
     * NODE-11: groups of paths; each group is satisfied when any of its paths
     * exists. A path ending in "/" must be a non-empty folder.
     *
     * @return list<list<string>>
     */
    public static function expectFiles(SiteConfig $config, ProjectInfo $info): array
    {
        $configured = $config->expectFiles();
        if ($configured !== []) {
            return array_map(static fn (string $p): array => [$p], $configured);
        }

        return match ($config->type()) {
            'laravel' => $info->usesMix
                ? [['public/mix-manifest.json']]
                : [['public/build/manifest.json', 'public/build/.vite/manifest.json']],
            'static' => [[($config->webDir() === '' ? '.' : $config->webDir()) . '/']],
            default => [],
        };
    }

    /**
     * NODE-13: paths copied from the live release when the build is skipped.
     *
     * @return list<string>
     */
    public static function buildOutputs(SiteConfig $config, ProjectInfo $info): array
    {
        $configured = $config->buildOutputs();
        if ($config->isLaravel() && $info->usesMix && $configured === ['public/build']) {
            return self::MIX_OUTPUTS;
        }
        if ($configured !== []) {
            return $configured;
        }

        return $config->type() === 'static' && $config->webDir() !== '' ? [$config->webDir()] : [];
    }
}
