<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 7 (§9.3): the Node version for the frontend build (NODE-01, NODE-04),
 * the package manager (NODE-07) and the build script. Skipped when the project
 * has no build script.
 */
final class NodeStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $info = $state->info;
        $files = $state->files;
        if ($info === null || $files === null) {
            return self::BACK;
        }
        $scripts = is_array($info->packageJson['scripts'] ?? null) ? array_filter($info->packageJson['scripts'], 'is_string') : [];
        if ($scripts === [] || ($state->buildScript !== '' && !$info->hasScript($state->buildScript) && !$info->hasScript('build'))) {
            return $w->skip();
        }
        if ($state->buildScript !== '' && !$info->hasScript($state->buildScript)) {
            $state->buildScript = 'build';
        }

        while (true) {
            $w->title(7, 'Node.js');
            $node = $w->services()->siteInspector()->nodeOptions($files);
            /** @var NodeSpec|null $spec */
            $spec = $node['spec'];
            $pmReason = null;
            try {
                $pm = PackageManager::detect($info->packageJson, $files->checker(), $files->reader());
                $pmReason = "{$pm->name} ({$pm->reason})";
            } catch (CpdeployException $e) {
                $ctx->error($e);
            }
            $summary = [];
            if ($state->buildScript !== '') {
                $summary[] = sprintf('package.json has "%s": "%s"', $state->buildScript, (string) ($scripts[$state->buildScript] ?? ''));
            }
            if ($spec !== null) {
                $summary[] = $spec->source . ' says ' . trim($spec->raw);
            }
            $summary[] = $state->packageManager !== 'auto' ? $state->packageManager . ' (chosen)' : ($pmReason ?? 'package manager unknown');
            $ctx->line(implode('   ·   ', $summary));

            $options = [];
            foreach ($node['installed'] as $installed) {
                $options['i:' . $installed->version] = sprintf('Node %-9s installed', $installed->version);
            }
            if ($node['download'] !== null && !isset($options['i:' . $node['download']])) {
                $options['d:' . $node['download']] = sprintf('Node %-9s download (~30 MB)', $node['download']);
            }
            $options['other'] = 'Other version…';
            $options['pm'] = 'Change package manager…';
            if (count($scripts) > 1) {
                $options['script'] = 'Build script…';
            }
            $options['none'] = 'No frontend build';
            $default = $state->buildScript === '' ? 'none' : $this->current($state->nodeVersion, $options);
            $choice = (string) $ctx->choose('Which Node.js builds the frontend?', $options, $default);
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
            if ($choice === 'none') {
                $state->buildScript = '';

                return self::NEXT;
            }
            if ($choice === 'pm') {
                $state->packageManager = (string) $ctx->asker->select('Package manager', [
                    'auto' => 'Detect from the lockfile' . ($pmReason !== null ? " ({$pmReason})" : ''),
                    'npm' => 'npm',
                    'pnpm' => 'pnpm',
                    'yarn' => 'yarn',
                ], $state->packageManager);
                continue;
            }
            if ($choice === 'script') {
                $state->buildScript = (string) $ctx->asker->select('Which package.json script builds the site?', array_combine(array_keys($scripts), array_map(static fn (string $k, string $v): string => "{$k}: {$v}", array_keys($scripts), $scripts)), $state->buildScript !== '' ? $state->buildScript : 'build');
                continue;
            }
            if ($state->buildScript === '') {
                $state->buildScript = 'build';
            }
            if ($choice === 'other') {
                $version = $w->text('Node version (22, 22.20.0, ^20, lts/*)', '', '22', static function (string $v): ?string {
                    try {
                        NodeSpec::parse($v, 'your answer');

                        return null;
                    } catch (CpdeployException $e) {
                        return $e->getMessage();
                    }
                });
                if ($version === null) {
                    continue;
                }
                $state->nodeVersion = $version;
            } else {
                $version = substr($choice, 2);
                $download = str_starts_with($choice, 'd:');
                $satisfies = $spec === null ? false : ($spec->kind === NodeSpec::LTS ? $download : $spec->matches($version));
                $state->nodeVersion = $satisfies ? 'auto' : explode('.', $version)[0];
            }
            if ($spec !== null && $state->nodeVersion !== 'auto') {
                $ctx->warn("The repo's {$spec->source} says " . trim($spec->raw) . ' — the site setting will override it');
            }

            return self::NEXT;
        }
    }

    /**
     * The option matching the current answer.
     *
     * @param array<string, string> $options
     */
    private function current(string $version, array $options): string
    {
        foreach (array_keys($options) as $key) {
            if (!str_starts_with($key, 'i:') && !str_starts_with($key, 'd:')) {
                continue;
            }
            $v = substr($key, 2);
            if ($version === 'auto' || $v === $version || explode('.', $v)[0] === $version) {
                return $key;
            }
        }
        return (string) array_key_first($options);
    }
}
