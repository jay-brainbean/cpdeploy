<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Format;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy releases <site> [protect|unprotect|delete <id>] [--json]` (§10.2, §10.5).
 */
#[AsCommand(name: 'releases', description: 'List, protect, unprotect or delete a site\'s releases')]
final class ReleasesCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('action', InputArgument::OPTIONAL, 'protect, unprotect or delete')
            ->addArgument('id', InputArgument::OPTIONAL, 'Release id, e.g. 20260929-030512');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $action = $input->getArgument('action');
        if (is_string($action) && $action !== '') {
            return $this->change($site, $action, $input, $output);
        }

        $doc = $this->services->siteStatus()->releases($site);
        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return 0;
        }
        /** @var list<array<string, mixed>> $releases */
        $releases = $doc['releases'];
        if ($releases === []) {
            $output->writeln("{$site} has no releases yet. Deploy it: cpdeploy deploy {$site}");

            return 0;
        }
        $theme = $this->services->theme();
        $table = new Table($output);
        $table->setHeaders(['', 'Release', 'Commit', 'Status', 'PHP', 'Size', 'Created']);
        foreach ($releases as $r) {
            $table->addRow([
                $r['status'] === 'live' ? $theme->symbol('live') : $theme->symbol('other'),
                $r['id'],
                trim($r['short'] . ' ' . Format::truncate((string) $r['message'], 28)),
                $r['status'] . ($r['protected'] === true ? ' (protected)' : ''),
                $r['php'] ?? '',
                is_int($r['size_kb']) ? Format::kilobytes($r['size_kb']) : '?',
                $this->when($r['created_at']),
            ]);
        }
        $table->render();

        return 0;
    }

    private function change(string $site, string $action, InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!in_array($action, ['protect', 'unprotect', 'delete'], true)) {
            throw new CpdeployException(ErrorCode::USAGE, "Unknown action: {$action}", "Use: cpdeploy releases {$site} [protect|unprotect|delete <id>]");
        }
        if (!is_string($id) || $id === '') {
            throw new CpdeployException(ErrorCode::USAGE, 'Which release? Pass its id', "List them with: cpdeploy releases {$site}");
        }
        $this->services->sites()->load($site);
        $releases = $this->services->releases();
        $releases->get($site, $id);
        $theme = $this->services->theme();

        if ($action === 'delete') {
            $asker = $this->services->asker($input);
            $confirmed = $input->getOption('yes') === true || ($asker->interactive() && $asker->confirm("Delete release {$id} of {$site}?", false));
            if (!$confirmed) {
                throw new CpdeployException($asker->interactive() ? ErrorCode::CANCELLED : ErrorCode::NEEDS_ANSWER, $asker->interactive() ? 'Cancelled' : 'This needs answers: --yes (to confirm the delete)', 'Nothing was deleted.');
            }
        }

        $actions = $this->services->releaseActions();
        match ($action) {
            'protect' => $actions->protect($site, $id, true),
            'unprotect' => $actions->protect($site, $id, false),
            default => $actions->delete($site, $id),
        };
        $output->writeln(sprintf('<fg=green>%s</> %s', $theme->symbol('ok'), match ($action) {
            'protect' => "Release {$id} is protected: cleanup will keep it",
            'unprotect' => "Release {$id} is no longer protected",
            default => "Release {$id} deleted",
        }));

        return 0;
    }

    private function when(mixed $iso): string
    {
        if (!is_string($iso) || $iso === '') {
            return '—';
        }
        try {
            return Format::relative(new DateTimeImmutable($iso), $this->services->clock()->now());
        } catch (\Exception) {
            return $iso;
        }
    }
}
