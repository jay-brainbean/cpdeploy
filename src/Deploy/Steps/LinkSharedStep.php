<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Config\Paths;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * B2: shared files and folders (REL-01, REL-03, REL-04). Missing shared folders
 * are created, seeded from the release when it has that path; then the release's
 * copy is replaced by a relative symlink. Laravel's per-release caches
 * (storage/framework/views, bootstrap/cache) are created when missing (REL-02).
 */
final class LinkSharedStep implements Step
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
    ) {
    }

    public function key(): string
    {
        return 'shared';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Shared files';
    }

    public function applies(DeployContext $ctx): bool
    {
        return true;
    }

    public function run(DeployContext $ctx): string
    {
        $release = $ctx->releaseDir();
        $shared = $this->paths->sharedDir($ctx->name());
        $this->fs->ensureDir($shared, Paths::MODE_ROOT);
        $linked = [];

        try {
            foreach ($ctx->site->sharedDirs() as $dir) {
                $target = $shared . '/' . $dir;
                $inRelease = $release . '/' . $dir;
                if (!is_dir($target)) {
                    $this->fs->ensureDir($target, Paths::MODE_PUBLIC_DIR);
                    if (is_dir($inRelease) && !is_link($inRelease)) {
                        $this->fs->copyTree($inRelease . '/.', $target);
                    }
                }
                if ($dir === 'storage/app' && !is_dir($target . '/public')) {
                    $this->fs->ensureDir($target . '/public', Paths::MODE_PUBLIC_DIR);
                }
                $this->replaceWithLink($inRelease, $target);
                $linked[] = $dir;
            }
            foreach ($ctx->site->sharedFiles() as $file) {
                $this->replaceWithLink($release . '/' . $file, $shared . '/' . $file);
                $linked[] = $file;
            }
            if ($ctx->site->isLaravel()) {
                foreach (['storage/framework/views', 'bootstrap/cache'] as $dir) {
                    if (!is_dir($release . '/' . $dir)) {
                        $this->fs->ensureDir($release . '/' . $dir, Paths::MODE_PUBLIC_DIR);
                    }
                }
            }
        } catch (RuntimeException $e) {
            throw new CpdeployException(ErrorCode::SHARED, "Couldn't link a shared path: " . $e->getMessage(), 'See the log.');
        }

        return self::summary($linked);
    }

    private function replaceWithLink(string $inRelease, string $target): void
    {
        if (is_link($inRelease) || file_exists($inRelease)) {
            $this->fs->deleteTree($inRelease);
        }
        $parent = dirname($inRelease);
        if (!is_dir($parent)) {
            $this->fs->ensureDir($parent, Paths::MODE_PUBLIC_DIR);
        }
        $this->fs->linkRelative($inRelease, $target);
    }

    /**
     * ".env · storage" for the progress line.
     *
     * @param list<string> $paths
     */
    public static function summary(array $paths): string
    {
        $tops = [];
        foreach ($paths as $path) {
            $tops[explode('/', $path)[0]] = true;
        }

        return implode(' · ', array_keys($tops));
    }
}
