<?php

declare(strict_types=1);

namespace Cpdeploy\Project;

/**
 * Project type and framework version at a commit (PRJ-01, PRJ-02, LAR-02).
 */
final class ProjectDetector
{
    public function detect(CommitFiles $files): ProjectInfo
    {
        $composer = $files->json('composer.json');
        $lock = $files->json('composer.lock');
        $package = $files->json('package.json');
        $hasArtisan = $files->exists('artisan');

        $requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        if (array_key_exists('laravel/framework', $requires) && $hasArtisan) {
            $type = 'laravel';
        } elseif (is_array($package) && is_array($package['scripts'] ?? null) && is_string($package['scripts']['build'] ?? null)) {
            $type = 'static';
        } elseif ($this->hasPhpFiles($files)) {
            $type = 'php';
        } else {
            $type = 'custom';
        }

        $deps = [];
        foreach (['dependencies', 'devDependencies'] as $key) {
            if (is_array($package[$key] ?? null)) {
                $deps += $package[$key];
            }
        }
        $usesMix = array_key_exists('laravel-mix', $deps) || ($files->exists('webpack.mix.js') && !array_key_exists('vite', $deps));

        return new ProjectInfo(
            $type,
            $composer,
            $lock,
            $package,
            ComposerInspector::packageVersion($lock, 'laravel/framework'),
            $hasArtisan,
            $usesMix,
        );
    }

    /**
     * PRJ-02 default web dir for a detected type.
     */
    public function defaultWebDir(string $type, CommitFiles $files): string
    {
        return match ($type) {
            'laravel' => 'public',
            'static' => $this->viteOutDir($files) ?? 'dist',
            'php' => $files->exists('public') ? 'public' : '',
            default => '',
        };
    }

    private function hasPhpFiles(CommitFiles $files): bool
    {
        foreach (['', 'public'] as $dir) {
            if ($dir !== '' && !$files->exists($dir)) {
                continue;
            }
            foreach ($files->list($dir) as $path) {
                if (str_ends_with($path, '.php')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Best effort: `outDir: 'build'` in vite.config.* (PRJ-02).
     */
    private function viteOutDir(CommitFiles $files): ?string
    {
        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.mts'] as $file) {
            $content = $files->read($file);
            if ($content !== null && preg_match('/outDir\s*:\s*[\'"]([A-Za-z0-9_\/.-]+)[\'"]/', $content, $m) === 1) {
                return trim($m[1], './') ?: null;
            }
        }

        return null;
    }
}
