<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Commands\ArtisanCommand;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;

/**
 * Laravel tools (§9.5.8): everything in the live release with its PHP, through
 * LaravelTools — the same service as `artisan`, `down` and `up` (ARC-03).
 */
final class LaravelToolsMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        while (true) {
            $this->ctx->title($site, 'Laravel tools');
            $choice = $this->ctx->choose('Laravel tools', [
                'artisan' => 'Run artisan command…',
                'down' => 'Maintenance mode: turn on…',
                'up' => 'Maintenance mode: turn off',
                'optimize' => 'Rebuild caches (optimize)',
                'clear' => 'Clear caches (optimize:clear)',
                'status' => 'Migration status',
                'migrate' => 'Run pending migrations…',
                'tinker' => 'Tinker',
                'log' => 'View laravel.log',
                'cron' => 'Show scheduler cron line',
            ]);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(fn () => $this->action($site, (string) $choice));
        }
    }

    private function action(string $site, string $choice): void
    {
        $tools = $this->ctx->services->laravelTools();
        $print = fn (string $line) => $this->ctx->output->writeln($line);
        switch ($choice) {
            case 'artisan':
                $command = trim($this->ctx->asker->text('php artisan', placeholder: 'route:list', required: true));
                $args = array_values(array_filter(preg_split('/\s+/', $command) ?: [], static fn (string $a): bool => $a !== ''));
                if ($args !== [] && $args[0] === 'artisan') {
                    array_shift($args);
                }
                ArtisanCommand::confirmDangerous($site, $args, $this->ctx->asker, false);
                $exit = $tools->artisan($site, $args, $this->ctx->asker->interactive() && $this->isTty(), $print);
                $this->ctx->line("Exit code {$exit}");
                $this->ctx->pause();
                break;

            case 'down':
                $retry = $this->ctx->asker->text('Retry-After seconds sent to visitors', '60', required: true, validate: static fn (string $v): ?string => ctype_digit($v) ? null : 'A whole number of seconds');
                $secret = trim($this->ctx->asker->text('Bypass secret (optional)', placeholder: 'leave empty for none'));
                $url = $tools->down($site, (int) $retry, $secret !== '' ? $secret : null, $this->ctx->reporter);
                if ($url !== null) {
                    $this->ctx->line('You can still open the site at: ' . $url);
                    $this->ctx->pause();
                }
                break;

            case 'up':
                $tools->up($site, $this->ctx->reporter);
                break;

            case 'optimize':
                $tools->caches($site, false, $this->ctx->reporter);
                break;

            case 'clear':
                if (!$this->ctx->asker->confirm('This also empties the application cache (Cache::…). Clear the caches?', false)) {
                    return;
                }
                $tools->caches($site, true, $this->ctx->reporter);
                break;

            case 'status':
                foreach (explode("\n", $tools->migrationStatus($site)) as $line) {
                    $this->ctx->line($line);
                }
                $this->ctx->pause();
                break;

            case 'migrate':
                $pending = $tools->pending($site);
                if ($pending->known && $pending->pending === []) {
                    $this->ctx->line('No pending migrations.');
                    $this->ctx->pause();

                    return;
                }
                $this->ctx->line($pending->known ? 'Pending: ' . implode(', ', $pending->pending) : 'Pending migrations: unknown (migrate:status could not be read).');
                if (!$this->ctx->asker->confirm('Run them now? (maintenance mode is on while they run)', false)) {
                    throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', '');
                }
                $ran = $tools->migrate($site, $this->ctx->reporter);
                $this->ctx->ok($ran === [] ? 'Migrations ran' : count($ran) . ' migration' . (count($ran) === 1 ? '' : 's') . ' ran');
                break;

            case 'tinker':
                $tools->artisan($site, ['tinker'], $this->isTty(), $print);
                break;

            case 'log':
                $log = $tools->log($site);
                if ($log === null) {
                    $this->ctx->line('No laravel.log yet.');
                    $this->ctx->pause();

                    return;
                }
                $this->ctx->services->pager($this->ctx->output)->show($log[0] . "\n\n" . implode("\n", $log[1]) . "\n", $this->ctx->asker->interactive());
                break;

            case 'cron':
                $this->ctx->line('Add this line in cPanel → Cron Jobs (it follows every release):');
                $this->ctx->output->writeln('  ' . $tools->cronLine($site), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                $this->ctx->pause();
                break;
        }
    }

    private function isTty(): bool
    {
        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }
}
