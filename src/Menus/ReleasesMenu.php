<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Format;
use Symfony\Component\Console\Helper\Table;

/**
 * Releases (§9.5.3) and Roll back (§9.5.2).
 */
final class ReleasesMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        while (true) {
            $this->ctx->title($site, 'Releases');
            $doc = $this->ctx->services->siteStatus()->releases($site, false);
            /** @var list<array<string, mixed>> $releases */
            $releases = $doc['releases'];
            if ($releases === []) {
                $this->ctx->line("No releases yet. Deploy {$site} first.");
                $this->ctx->pause();

                return;
            }
            $table = new Table($this->ctx->output);
            $table->setHeaders(['Release', 'Commit', 'Created', 'PHP', 'Status', '']);
            $options = [];
            foreach ($releases as $r) {
                $status = (string) $r['status'];
                $protected = $r['protected'] === true ? ($this->ctx->theme->unicode ? '🔒' : 'P') : '';
                $table->addRow([
                    (string) $r['id'],
                    trim($r['short'] . ' ' . Format::truncate((string) $r['message'], 28)),
                    $this->ctx->when($r['created_at']),
                    (string) ($r['php'] ?? ''),
                    $status === 'live' ? $this->ctx->theme->symbol('live') . ' live' : $status,
                    $protected,
                ]);
                $options[(string) $r['id']] = sprintf('%s · %s · %s', $r['id'], $r['short'], $status . ($protected !== '' ? ' · protected' : ''));
            }
            $table->render();
            $choice = $this->ctx->choose('Choose a release', $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(fn () => $this->release($site, (string) $choice));
        }
    }

    /**
     * RB-02 with the picker (or a chosen id), RB-04 warnings, RB-05 confirmation.
     */
    public function rollback(string $site, ?string $id): void
    {
        $this->ctx->title($site, 'Roll back');
        $result = $this->ctx->services->rollbackService()->rollback($site, $id, false, false, false, false, $this->ctx->asker, $this->ctx->reporter);
        foreach (array_values(array_unique($result->warnings)) as $warning) {
            $this->ctx->warn($warning);
        }
        if ($result->message !== '') {
            $this->ctx->warn($result->message);
        }
        $this->ctx->pause();
    }

    private function release(string $site, string $id): void
    {
        $releases = $this->ctx->services->releases();
        $release = $releases->get($site, $id);
        $live = $releases->liveId($site) === $id;
        $options = ['details' => 'Details'];
        if (!$live) {
            $options['rollback'] = 'Roll back to this release';
        }
        $options['protect'] = $release->isProtected() ? 'Unprotect' : 'Protect';
        if (!$live) {
            $options['delete'] = 'Delete';
        }
        $choice = $this->ctx->choose("Release {$id}", $options);
        switch ($choice) {
            case 'details':
                $this->ctx->title($site, 'Release ' . $id);
                foreach (['status', 'commit', 'branch', 'ref', 'message', 'author', 'committed_at', 'created_at', 'activated_at', 'php.version', 'php.binary', 'node.version', 'laravel', 'deployed_by'] as $key) {
                    $value = $release->get($key);
                    if (is_scalar($value) && $value !== '') {
                        $this->ctx->line(sprintf('%-14s %s', $key, (string) $value));
                    }
                }
                $migrations = $release->get('migrations.list');
                if (is_array($migrations) && $migrations !== []) {
                    $this->ctx->line('migrations     ' . implode(', ', array_filter($migrations, 'is_string')));
                }
                $this->ctx->pause();
                break;
            case 'rollback':
                $this->rollback($site, $id);
                break;
            case 'protect':
                $this->ctx->services->releaseActions()->protect($site, $id, !$release->isProtected());
                $this->ctx->ok($release->isProtected() ? "Release {$id} is no longer protected" : "Release {$id} is protected: cleanup will keep it");
                break;
            case 'delete':
                if (!$this->ctx->asker->confirm("Delete release {$id}?", false)) {
                    throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', '');
                }
                $this->ctx->services->releaseActions()->delete($site, $id);
                $this->ctx->ok("Release {$id} deleted");
                break;
        }
    }
}
