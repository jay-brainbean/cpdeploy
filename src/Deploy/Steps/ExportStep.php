<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy\Steps;

use Cpdeploy\Deploy\DeployContext;
use Cpdeploy\Deploy\Release;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Deploy\StateFile;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Fs;

/**
 * B1: create releases/<id>, write .release.json (status building), export the
 * commit (GIT-13). A committed root auth.json is removed (SEC-15).
 */
final class ExportStep implements Step
{
    public function __construct(
        private readonly ReleaseManager $releases,
        private readonly GitRepository $git,
        private readonly Fs $fs,
        private readonly Clock $clock,
    ) {
    }

    public function key(): string
    {
        return 'export';
    }

    public function label(DeployContext $ctx): string
    {
        return 'Export';
    }

    public function applies(DeployContext $ctx): bool
    {
        return true;
    }

    public function run(DeployContext $ctx): string
    {
        $commit = $ctx->commit ?? throw new \LogicException('No target commit');
        $php = $ctx->sitePhp();
        $release = $this->releases->create($ctx->name());
        $ctx->release = $release;
        $ctx->state?->update(['phase' => StateFile::BUILDING, 'release' => $release->id]);

        foreach ([
            'status' => Release::BUILDING,
            'protected' => false,
            'commit' => $commit->sha,
            'short' => $commit->short,
            'branch' => $ctx->site->branch(),
            'ref' => $ctx->ref,
            'message' => $commit->subject,
            'author' => $commit->author,
            'committed_at' => gmdate('Y-m-d\TH:i:s\Z', $commit->timestamp),
            'created_at' => $this->clock->iso(),
            'activated_at' => null,
            'php' => ['version' => $php->version, 'family' => $php->family, 'binary' => $php->binary],
            'node' => null,
            'composer' => ['ran' => false, 'reused_from' => null, 'version' => $ctx->composerVersion],
            'build' => ['ran' => false, 'reused_from' => null],
            'migrations' => ['ran' => false, 'list' => []],
            'laravel' => $ctx->info?->laravelVersion,
            'durations' => [],
            'deployed_by' => $ctx->deployedBy,
        ] as $key => $value) {
            $release->set($key, $value);
        }
        $release->save($this->fs);

        $this->git->export($ctx->mirror(), $ctx->sha(), $release->dir);

        // SEC-15: Composer credentials never live in a release.
        if (is_file($release->dir . '/auth.json') && !is_link($release->dir . '/auth.json')) {
            @unlink($release->dir . '/auth.json');
            $ctx->warn('auth.json is committed to your repo — it was removed from the release. Remove it from the repo and rotate those credentials.');
        }

        return sprintf('%s → releases/%s', $commit->short, $release->id);
    }
}
