<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Commands\DeployCommand;
use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Deploy\DeployResult;
use Cpdeploy\Git\Commit;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Format;

/**
 * The deploy screen (§9.4): the Deployer's own questions and progress, then the
 * result — or the failure screen with *View full log*, *Retry* and *Retry with
 * changes…*. Also *Deploy with changes…* (§9.5.1).
 */
final class DeployScreen
{
    private const CHANGES = [
        'ref' => 'Different branch, tag or commit…',
        'skip_composer' => 'Skip composer install (reuse vendor/)',
        'force_composer' => 'Force composer install',
        'skip_build' => 'Skip frontend build (reuse the build output)',
        'skip_migrations' => 'Skip migrations',
        'skip_optimize' => 'Skip optimize',
    ];

    public function __construct(private readonly MenuContext $ctx)
    {
    }

    /**
     * The result of the deploy that finished, or null (cancelled, or Back after a failure).
     */
    public function deploy(string $site, DeployFlags $flags = new DeployFlags()): ?DeployResult
    {
        while (true) {
            $this->ctx->title($site, 'Deploy');
            try {
                $result = $this->ctx->services->deployer()->deploy($site, $flags, $this->ctx->asker, $this->ctx->reporter);
                DeployCommand::report($result, $this->ctx->output, $this->ctx->theme);
                $this->ctx->pause();

                return $result;
            } catch (CpdeployException $e) {
                if ($e->errorCode === ErrorCode::CANCELLED) {
                    $this->ctx->line($e->getMessage());

                    return null;
                }
                $this->ctx->error($e);
                $next = $this->failure($site, $e, $flags);
                if ($next === null) {
                    return null;
                }
                $flags = $next;
            }
        }
    }

    /**
     * §9.5.1: one-off options, then the normal deploy. Null = Back.
     */
    public function withChanges(string $site): ?DeployFlags
    {
        while (true) {
            $chosen = $this->ctx->asker->multiselect('Deploy with changes', self::CHANGES, [], 'Space to select, Enter to continue');
            if (in_array('skip_composer', $chosen, true) && in_array('force_composer', $chosen, true)) {
                $this->ctx->warn("Skip composer install and Force composer install can't both be chosen.");
                continue;
            }
            if ($chosen === []) {
                return null;
            }
            $ref = null;
            if (in_array('ref', $chosen, true)) {
                $ref = $this->ref($site);
                if ($ref === null) {
                    return null;
                }
            }

            return new DeployFlags(
                ref: $ref,
                composer: in_array('skip_composer', $chosen, true) ? DeployFlags::NO : (in_array('force_composer', $chosen, true) ? DeployFlags::YES : DeployFlags::AUTO),
                migrate: in_array('skip_migrations', $chosen, true) ? DeployFlags::NO : DeployFlags::AUTO,
                skipBuild: in_array('skip_build', $chosen, true),
                skipOptimize: in_array('skip_optimize', $chosen, true),
            );
        }
    }

    /**
     * The failure screen. Returns the flags to retry with, or null for Back.
     */
    private function failure(string $site, CpdeployException $e, DeployFlags $flags): ?DeployFlags
    {
        $build = $e->exitCode() === 4;
        while (true) {
            $options = [];
            if ($e->logPath !== null) {
                $options['log'] = 'View full log';
            }
            if ($build) {
                $options['retry'] = 'Retry';
                $options['changes'] = 'Retry with changes…';
            }
            $options['back'] = 'Back to menu';
            $choice = $this->ctx->asker->select('What next?', $options, $build ? 'retry' : 'back');
            switch ($choice) {
                case 'log':
                    $this->ctx->services->pager($this->ctx->output)->show((string) @file_get_contents((string) $e->logPath), $this->ctx->asker->interactive());
                    break;
                case 'retry':
                    return $flags;
                case 'changes':
                    $changed = $this->withChanges($site);
                    if ($changed !== null) {
                        return $changed;
                    }
                    break;
                default:
                    return null;
            }
        }
    }

    /**
     * A `search` over branches, tags and the last 100 commits of the site's branch.
     */
    private function ref(string $site): ?string
    {
        $mirrors = $this->ctx->services->mirrors();
        $this->ctx->reporter->start('Fetching from GitHub');
        try {
            $mirrors->update($site);
        } catch (CpdeployException $e) {
            $this->ctx->reporter->fail($e->getMessage());
            throw $e;
        }
        $this->ctx->reporter->succeed('');
        $branch = $this->ctx->services->sites()->load($site)->branch();
        $options = [];
        foreach ($mirrors->branches($site) as $b) {
            $options[$b] = 'branch  ' . $b . ($b === $branch ? '  (current)' : '');
        }
        foreach ($mirrors->tags($site) as $tag) {
            $options[$tag] = 'tag     ' . $tag;
        }
        foreach ($mirrors->commits($site, $branch) as $commit) {
            /** @var Commit $commit */
            $options[$commit->sha] = 'commit  ' . $commit->short . '  ' . Format::truncate($commit->subject, 50);
        }
        $choice = $this->ctx->pick('Deploy which branch, tag or commit?', $options);

        return $choice === MenuContext::BACK ? null : $choice;
    }
}
