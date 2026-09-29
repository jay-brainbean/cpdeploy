<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Git\ManualKeyInstructions;

/**
 * Deploy key (§9.5.10): show, test (GIT-06), rotate (GIT-18) — SiteKeys, as
 * `cpdeploy key` (ARC-03).
 */
final class KeyMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        $keys = $this->ctx->services->siteKeys();
        while (true) {
            $this->ctx->title($site, 'Deploy key');
            $choice = $this->ctx->choose('Deploy key', ['show' => 'Show public key', 'test' => 'Test access', 'rotate' => 'Rotate key']);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(function () use ($site, $choice, $keys): void {
                switch ($choice) {
                    case 'show':
                        $info = $keys->info($site);
                        if (!$info['exists']) {
                            $this->ctx->warn("The deploy key {$info['path']} is missing — rotate it to create a new one.");
                        } else {
                            $this->ctx->output->writeln((string) $info['public'], \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                            $this->ctx->line('Fingerprint: ' . ($info['fingerprint'] ?? 'unknown'));
                        }
                        $this->ctx->line('On GitHub: ' . ($info['id'] !== null ? "key id {$info['id']}" : 'added by hand') . ' · ' . $info['keysUrl']);
                        $this->ctx->pause();
                        break;
                    case 'test':
                        $count = $keys->test($site);
                        $this->ctx->ok(sprintf('The deploy key can read the repository (%d branch%s)', $count, $count === 1 ? '' : 'es'));
                        $this->ctx->pause();
                        break;
                    case 'rotate':
                        if (!$this->ctx->asker->confirm('Create a new deploy key and replace the current one? (the old key keeps working until the new one is tested)', true)) {
                            return;
                        }
                        $result = $keys->rotate($site, function (ManualKeyInstructions $i) use ($site): bool {
                            foreach ($i->lines($site) as $line) {
                                $this->ctx->line($line);
                            }

                            return $this->ctx->asker->confirm('Added it on GitHub (read-only)? cpdeploy tests it next', true);
                        }, $this->ctx->reporter);
                        $this->ctx->ok('New deploy key in place and tested');
                        if ($result->manualDelete !== null) {
                            $this->ctx->warn($result->manualDelete);
                        }
                        $this->ctx->pause();
                        break;
                }
            });
        }
    }
}
