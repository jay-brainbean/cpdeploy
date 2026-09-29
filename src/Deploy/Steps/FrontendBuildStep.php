<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Config\Paths;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Deploy\DeployPlan;
use Cpdeploy\Project\NodeInspector;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Runtime\PackageManagerChoice;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Shell;
use RuntimeException;

/**
 * B5: resolve the package manager (NODE-07/08) → install → build → check the
 * outputs (NODE-11) → remove node_modules (NODE-12); or reuse the live
 * release's build outputs (NODE-13). The build sees the shared production .env
 * (BLD-04) and runs without NODE_ENV or CI (NODE-10).
 */
final class FrontendBuildStep implements Step
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly PackageManager $packageManager,
    ) {
    }

    public function key(): string
    {
        return 'frontend_build';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Frontend build';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->build !== DeployPlan::BUILD_NONE;
    }

    public function run(DeployContext $ctx): string
    {
        if ($ctx->plan()->build === DeployPlan::BUILD_REUSE && $ctx->live !== null) {
            return $this->reuse($ctx);
        }

        $pm = $ctx->packageManager ?? new PackageManagerChoice(PackageManagerChoice::NPM, null, 'default');
        $node = $ctx->node;
        if ($node === null) {
            throw new CpdeployException(ErrorCode::NODE_NONE, 'No Node.js was resolved for the build', 'Set a Node version (Manage site → Node version) or add .nvmrc.', exitCode: 4);
        }
        [$command, $extraPath] = $this->packageManager->ensure($pm, $node, null, 4);
        $release = $ctx->releaseDir();
        $lockfile = NodeInspector::LOCKFILES[$pm->name] ?? 'package-lock.json';
        [$install, $warning] = PackageManager::installCommand($pm, $command, is_file($release . '/' . $lockfile));
        if ($warning !== null) {
            $ctx->warn($warning);
        }

        $env = [];
        $heap = $ctx->site->maxOldSpaceMb();
        if ($heap !== null) {
            $env['NODE_OPTIONS'] = '--max-old-space-size=' . $heap;
        }
        $path = [...$ctx->pathPrefix(true), ...$extraPath];
        $pmName = $pm->name === PackageManagerChoice::YARN_BERRY ? 'yarn' : $pm->name;

        $this->shell->mustRun(
            $install,
            $ctx->options($ctx->timeout('node_install'), "{$pmName} install", $env, true)->withPathPrefix($path),
            ErrorCode::NODE_INSTALL,
            "{$pmName} install failed",
            'See the last lines above and the log.',
        );
        $script = $ctx->site->buildScript();
        $this->shell->mustRun(
            PackageManager::runCommand($command, $script),
            $ctx->options($ctx->timeout('node_build'), "{$pmName} run {$script}", $env, true)->withPathPrefix($path),
            ErrorCode::NODE_BUILD,
            "{$pmName} run {$script} failed",
            'See the last lines above and the log.',
        );

        foreach (NodeInspector::expectFiles($ctx->site, $ctx->info()) as $group) {
            if (!$this->anyExists($release, $group)) {
                throw new CpdeployException(
                    ErrorCode::BUILD_OUTPUT,
                    sprintf("The build finished but %s wasn't created", implode(' or ', $group)),
                    'Check the build script and its output folder (Manage site → Node → expected files).',
                );
            }
        }

        if ($ctx->site->removeNodeModules() && is_dir($release . '/node_modules') && !is_link($release . '/node_modules')) {
            try {
                $this->fs->deleteTree($release . '/node_modules');
            } catch (RuntimeException $e) {
                $ctx->warn("Couldn't remove node_modules: " . $e->getMessage());
            }
        }

        $ctx->release?->set('node', ['version' => $node->version, 'package_manager' => trim($pmName . ' ' . ($pm->version ?? ''))]);
        $ctx->release?->set('build', ['ran' => true, 'reused_from' => null]);
        $installWord = match (true) {
            $pm->name === PackageManagerChoice::NPM && str_contains(implode(' ', $install), ' ci ') => 'npm ci',
            default => $pmName . ' install',
        };

        return sprintf('Node %d · %s + %s run %s', $node->major(), $installWord, $pmName, $script);
    }

    private function reuse(DeployContext $ctx): string
    {
        $live = $ctx->live;
        $outputs = NodeInspector::buildOutputs($ctx->site, $ctx->info());
        foreach ($outputs as $path) {
            $from = $live?->dir . '/' . $path;
            $to = $ctx->releaseDir() . '/' . $path;
            if (!file_exists($from)) {
                continue;
            }
            if (is_link($to) || file_exists($to)) {
                $this->fs->deleteTree($to);
            }
            if (!is_dir(dirname($to))) {
                $this->fs->ensureDir(dirname($to), Paths::MODE_PUBLIC_DIR);
            }
            try {
                $this->fs->copyTree($from, $to);
            } catch (RuntimeException $e) {
                throw new CpdeployException(ErrorCode::NODE_BUILD, "Couldn't copy {$path} from the live release: " . $e->getMessage(), 'Deploy again without skipping the build.');
            }
        }
        $ctx->release?->set('node', $live?->get('node'));
        $ctx->release?->set('build', ['ran' => false, 'reused_from' => $live?->id]);

        return 'reused ' . implode(', ', $outputs) . ' from ' . $live?->id;
    }

    /**
     * @param list<string> $group
     */
    private function anyExists(string $release, array $group): bool
    {
        foreach ($group as $path) {
            $full = $release . '/' . rtrim($path, '/');
            if (str_ends_with($path, '/')) {
                if (is_dir($full) && array_diff(scandir($full) ?: [], ['.', '..']) !== []) {
                    return true;
                }
            } elseif (file_exists($full)) {
                return true;
            }
        }

        return false;
    }
}
