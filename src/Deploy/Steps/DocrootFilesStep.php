<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Config\Paths;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * B3: docroot extras linked inside the web dir (REL-05) and the cPanel PHP
 * handler block injected into <web_dir>/.htaccess for the site PHP (DOC-04).
 * When the web dir only appears after the frontend build (static sites), the
 * builder runs this step again after B5.
 */
final class DocrootFilesStep implements Step
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly DocrootManager $docroots,
    ) {
    }

    public function key(): string
    {
        return 'docroot';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Docroot files';
    }

    public function applies(DeployContext $ctx): bool
    {
        return true;
    }

    /**
     * Whether the web dir exists yet in the new release.
     */
    public static function ready(DeployContext $ctx): bool
    {
        $web = $ctx->release?->webPath($ctx->site->webDir());

        return $web !== null && is_dir($web) && !is_link($web);
    }

    public function run(DeployContext $ctx): string
    {
        if (!self::ready($ctx)) {
            return 'after the build';
        }
        $web = (string) $ctx->release?->webPath($ctx->site->webDir());
        $shared = $this->paths->sharedDocrootDir($ctx->name());
        $done = [];

        try {
            foreach ($ctx->site->docrootDirs() as $dir) {
                if ($this->providedByRepo($ctx, $web, $dir)) {
                    continue;
                }
                $this->fs->ensureDir($this->paths->sharedDir($ctx->name()), Paths::MODE_ROOT);
                $this->fs->ensureDir($shared, Paths::MODE_ROOT);
                $this->fs->ensureDir($shared . '/' . $dir, Paths::MODE_PUBLIC_DIR);
                $this->link($web . '/' . $dir, $shared . '/' . $dir);
                $done[] = $dir;
            }
            foreach ($ctx->site->docrootFiles() as $file) {
                if (!is_file($shared . '/' . $file) || $this->providedByRepo($ctx, $web, $file)) {
                    continue;
                }
                $this->link($web . '/' . $file, $shared . '/' . $file);
                $done[] = $file;
            }
            $php = $ctx->sitePhp();
            if ($this->docroots->injectHandler($ctx->site, $web, $php->family, PhpInstall::majorMinorOf($php->version))) {
                $done[] = 'PHP handler';
            }
        } catch (RuntimeException $e) {
            throw new CpdeployException(ErrorCode::SHARED, "Couldn't link a docroot file: " . $e->getMessage(), 'See the log.');
        }

        return implode(' · ', $done);
    }

    private function providedByRepo(DeployContext $ctx, string $web, string $path): bool
    {
        $full = $web . '/' . $path;
        if (file_exists($full) && !is_link($full)) {
            $ctx->log?->write("docroot extra '{$path}' provided by the repo — not linked");

            return true;
        }

        return false;
    }

    private function link(string $at, string $target): void
    {
        if (is_link($at)) {
            @unlink($at);
        }
        if (!is_dir(dirname($at))) {
            $this->fs->ensureDir(dirname($at), Paths::MODE_PUBLIC_DIR);
        }
        $this->fs->linkRelative($at, $target);
    }
}
