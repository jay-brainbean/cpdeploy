<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Ui\Format;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy status [site] [--json]` (§10.2, §10.5).
 */
#[AsCommand(name: 'status', description: 'Show your sites, or one site in detail')]
final class StatusCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site name (omit for all sites)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->services->siteStatus();
        $name = $input->getArgument('site');
        $json = $input->getOption('json') === true;

        if (is_string($name) && $name !== '') {
            $this->services->sites()->load($name);
            $entry = $status->site($name);
            if ($json) {
                $output->writeln((string) json_encode(['schema' => 1, 'sites' => [$entry]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

                return 0;
            }
            $this->detail($entry, $output);

            return 0;
        }

        $all = $status->all();
        if ($json) {
            $output->writeln((string) json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return 0;
        }
        /** @var list<array<string, mixed>> $sites */
        $sites = $all['sites'];
        if ($sites === []) {
            $output->writeln('No sites are set up yet.');

            return 0;
        }
        $table = new Table($output);
        $table->setHeaders(['Site', 'Domain', 'Type', 'Live', 'Deployed', 'Last']);
        foreach ($sites as $site) {
            $table->addRow([
                $site['name'],
                $site['domain'] ?? '(invalid site.yml)',
                $site['type'] ?? '',
                $this->liveLabel($site),
                $this->when($site['live']['deployed_at'] ?? null),
                $this->lastLabel($site),
            ]);
        }
        $table->render();

        return 0;
    }

    /**
     * @param array<string, mixed> $site
     */
    private function detail(array $site, OutputInterface $output): void
    {
        $theme = $this->services->theme();
        $output->writeln(sprintf('<options=bold>%s</> · %s · %s · branch %s', $site['name'], $site['domain'] ?? '?', $site['type'] ?? '?', $site['branch'] ?? '?'));
        if (isset($site['error'])) {
            $output->writeln(sprintf('<fg=red>%s</> %s', $theme->symbol('fail'), $site['error']));
        }
        $live = $site['live'];
        if (is_array($live)) {
            $output->writeln(sprintf(
                'Live: %s "%s" · release %s · deployed %s · PHP %s%s',
                substr((string) $live['commit'], 0, 7),
                Format::truncate((string) $live['message'], 40),
                $live['release'],
                $this->when($live['deployed_at']),
                $live['php'] ?? '?',
                $live['node'] !== null ? ' · Node ' . $live['node'] : '',
            ));
        } else {
            $output->writeln('Live: not deployed yet');
        }
        $output->writeln('Last operation: ' . $this->lastLabel($site));
        if ($site['maintenance'] === true) {
            $output->writeln(sprintf('<fg=yellow>%s</> The site is in maintenance mode (cpdeploy up %s)', $theme->symbol('warn'), $site['name']));
        }
        if ($site['locked'] === true) {
            $output->writeln(sprintf('<fg=yellow>%s</> An operation is running right now', $theme->symbol('warn')));
        }
        if ($site['interrupted'] === true) {
            $output->writeln(sprintf('<fg=red>%s</> An earlier operation was interrupted. Run: cpdeploy recover %s', $theme->symbol('fail'), $site['name']));
        }
    }

    /**
     * @param array<string, mixed> $site
     */
    private function liveLabel(array $site): string
    {
        $live = $site['live'];
        if (!is_array($live)) {
            return '—';
        }
        $label = substr((string) $live['commit'], 0, 7) . ' ' . Format::truncate((string) $live['message'], 24);
        if ($site['maintenance'] === true) {
            $label .= ' (maintenance)';
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $site
     */
    private function lastLabel(array $site): string
    {
        $last = $site['last'];
        if (!is_array($last)) {
            return $site['interrupted'] === true ? 'interrupted' : '—';
        }

        return sprintf('%s %s %s', $last['action'], $last['result'], $this->when($last['at']));
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
