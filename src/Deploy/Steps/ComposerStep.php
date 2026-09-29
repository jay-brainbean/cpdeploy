<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Config\Paths;
use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Deploy\DeployPlan;
use Cpdeploy\Project\ComposerInspector;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\Shell;
use RuntimeException;

/**
 * B4: `composer install` (CMP-04) with the site PHP through the shims (CMP-02),
 * or reuse: a full `cp -a` of the live vendor/ followed by `composer
 * dump-autoload` (CMP-05). Composer scripts such as package:discover run here.
 */
final class ComposerStep implements Step
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly Paths $paths,
        private readonly Masker $masker,
    ) {
    }

    public function key(): string
    {
        return 'composer';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Composer';
    }

    public function applies(DeployContext $ctx): bool
    {
        return $ctx->plan()->composer !== DeployPlan::COMPOSER_NONE;
    }

    /**
     * CMP-03: the Composer environment. COMPOSER_HOME and the cache stay the user's.
     *
     * @return array<string, string>
     */
    public function environment(DeployContext $ctx): array
    {
        $env = ['COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_MEMORY_LIMIT' => '-1'];
        $auth = $this->paths->sharedAuthJson($ctx->name());
        if (is_file($auth)) {
            @chmod($auth, Paths::MODE_SECRET_FILE);
            $json = (string) file_get_contents($auth);
            if (is_array(json_decode($json, true))) {
                $env['COMPOSER_AUTH'] = $json;
                $this->masker->addComposerAuth($json);
            } else {
                $ctx->warn('shared/auth.json is not valid JSON — Composer credentials were not used');
            }
        }

        return $env;
    }

    public function run(DeployContext $ctx): string
    {
        $plan = $ctx->plan();
        $release = $ctx->release;
        $php = $ctx->sitePhp();
        $phar = (string) $ctx->composerPhar;
        $env = $this->environment($ctx);
        $timeout = $ctx->timeout('composer');

        if ($plan->composer === DeployPlan::COMPOSER_REUSE) {
            $live = $ctx->live;
            if ($live !== null && is_dir($live->dir . '/vendor')) {
                try {
                    // A full copy, never hardlinks: autoload files are rewritten in place (CMP-05).
                    $this->fs->copyTree($live->dir . '/vendor', $ctx->releaseDir() . '/vendor', $timeout);
                } catch (RuntimeException $e) {
                    throw new CpdeployException(ErrorCode::COMPOSER, "Couldn't copy vendor/ from the live release: " . $e->getMessage(), 'Deploy again with --composer=yes.');
                }
                $options = $ctx->options($timeout, 'composer dump-autoload', $env);
                $this->shell->mustRun(
                    [$php->binary, $phar, 'dump-autoload', '--optimize', '--no-dev', '--no-interaction'],
                    $options,
                    ErrorCode::COMPOSER,
                    'composer dump-autoload failed',
                    'See the last lines above and the log. Deploy with --composer=yes to reinstall.',
                );
                $release?->set('composer', ['ran' => false, 'reused_from' => $live->id, 'version' => $ctx->composerVersion]);

                return sprintf('PHP %s · vendor/ reused from %s', $php->majorMinor(), $live->id);
            }
            $ctx->note('composer: installing — the live release has no vendor/ to reuse');
        }

        $flags = $ctx->site->composerInstallFlags();
        $options = $ctx->options($timeout, 'composer install', $env);
        $this->shell->mustRun(
            [$php->binary, $phar, 'install', ...$flags],
            $options,
            ErrorCode::COMPOSER,
            'composer install failed',
            'See the last lines above and the log. Common causes: memory (E_OOM), private packages (Manage site → Composer credentials).',
        );
        $release?->set('composer', ['ran' => true, 'reused_from' => null, 'version' => $ctx->composerVersion]);
        $count = count(ComposerInspector::lockPackages($ctx->info?->composerLock));

        return sprintf('PHP %s · %d packages', $php->majorMinor(), $count);
    }
}
