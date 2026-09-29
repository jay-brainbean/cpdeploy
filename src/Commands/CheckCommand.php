<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Check\CheckGroup;
use Cpdeploy\Check\CheckResult;
use Cpdeploy\Check\SiteCheck;
use Cpdeploy\Git\HostKeyRefresh;
use Cpdeploy\Services;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Masker;
use Cpdeploy\Ui\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy check [site] [--probe] [--json] [--refresh-host-keys]` (§9.7).
 * Exit 0 when nothing failed, otherwise 3.
 */
#[AsCommand(name: 'check', description: 'Check that this server, this account and your sites can run cpdeploy')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Only this site (default: every site)')
            ->addOption('probe', null, InputOption::VALUE_NONE, 'Also ask each domain which PHP it really serves')
            ->addOption('refresh-host-keys', null, InputOption::VALUE_NONE, "Replace ~/cpdeploy/known_hosts with GitHub's current SSH host keys");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('refresh-host-keys') === true) {
            return $this->refreshHostKeys($input, $output);
        }
        $site = $input->getArgument('site');
        $site = is_string($site) && $site !== '' ? $site : null;
        if ($site !== null && !$this->services->sites()->exists($site)) {
            throw new CpdeployException(ErrorCode::USAGE, "There is no site named {$site}", 'Sites: ' . (implode(', ', $this->services->sites()->names()) ?: 'none yet'));
        }
        $groups = self::groups($this->services, $site, $input->getOption('probe') === true);

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'groups' => array_map(static fn (CheckGroup $g): array => $g->toArray(), $groups),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::failed($groups) ? 3 : 0;
        }

        $output->writeln('<options=bold>cpdeploy · Server check</>');
        self::render($groups, $output, $this->services->theme(), $this->services->masker());
        $this->offerFixes($groups, $input, $output);

        return self::failed($groups) ? 3 : 0;
    }

    /**
     * The server groups, then one group per site (or just $site).
     *
     * @return list<CheckGroup>
     */
    public static function groups(Services $services, ?string $site = null, bool $probe = false): array
    {
        $groups = $services->serverCheck()->run();
        foreach ($site !== null ? [$site] : $services->sites()->names() as $name) {
            $groups[] = $services->siteCheck()->run($name, $probe);
        }

        return $groups;
    }

    /**
     * @param list<CheckGroup> $groups
     */
    public static function failed(array $groups): bool
    {
        return array_filter($groups, static fn (CheckGroup $g): bool => $g->hasFailures()) !== [];
    }

    /**
     * §9.7: .env with the wrong mode is fixed on request.
     *
     * @param list<CheckGroup> $groups
     */
    private function offerFixes(array $groups, InputInterface $input, OutputInterface $output): void
    {
        $asker = $this->services->asker($input);
        foreach ($groups as $group) {
            foreach ($group->checks as $check) {
                if ($check->id !== SiteCheck::ENV_MODE) {
                    continue;
                }
                $site = substr($group->name, strlen('Site '));
                $fix = $input->getOption('yes') === true || ($asker->interactive() && $asker->confirm("Set shared/.env of {$site} to mode 600?", true));
                if ($fix) {
                    $this->services->siteCheck()->fixEnvMode($site);
                    $output->writeln(sprintf('<fg=green>%s</> %s: shared/.env is now 600', $this->services->theme()->symbol('ok'), $site));
                }
            }
        }
    }

    /**
     * GIT-03: GitHub's current keys from GET /meta, their fingerprints, and a
     * confirmation before ~/cpdeploy/known_hosts is replaced.
     */
    private function refreshHostKeys(InputInterface $input, OutputInterface $output): int
    {
        $refresh = $this->services->hostKeyRefresh();
        [$text, $found] = $refresh->fetch();
        foreach (HostKeyRefresh::lines($found) as $line) {
            $output->writeln($line);
        }
        $asker = $this->services->asker($input);
        if ($input->getOption('yes') !== true) {
            if (!$asker->interactive()) {
                throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'Replacing the host keys needs confirmation', 'Add --yes after comparing the fingerprints');
            }
            if (!$asker->confirm('Replace ~/cpdeploy/known_hosts with these keys?', false)) {
                $output->writeln('Nothing was changed.');

                return 0;
            }
        }
        $refresh->apply($text);
        $output->writeln(sprintf('<fg=green>%s</> ~/cpdeploy/known_hosts replaced', $this->services->theme()->symbol('ok')));

        return 0;
    }

    /**
     * The §9.7 listing; $problemsOnly shows only ⚠ and ✗ lines (UIG-02).
     *
     * @param list<CheckGroup> $groups
     */
    public static function render(array $groups, OutputInterface $output, Theme $theme, Masker $masker, bool $problemsOnly = false): void
    {
        $counts = [CheckResult::OK => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0];
        foreach ($groups as $group) {
            $shown = array_filter($group->checks, static fn (CheckResult $c): bool => !$problemsOnly || in_array($c->status, [CheckResult::WARN, CheckResult::FAIL], true));
            foreach ($group->checks as $check) {
                if (isset($counts[$check->status])) {
                    $counts[$check->status]++;
                }
            }
            if ($shown === []) {
                continue;
            }
            $output->writeln('');
            $output->writeln('<options=bold>' . $group->name . '</>');
            foreach ($shown as $check) {
                $output->writeln(sprintf('  %s %s', $theme->status($check->status), self::escape($masker->mask($check->message))));
                if ($check->hint !== '' && $check->status !== CheckResult::OK) {
                    $output->writeln(sprintf('      <fg=gray>%s %s</>', $theme->symbol('arrow'), self::escape($check->hint)));
                }
            }
        }
        $output->writeln('');
        $plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
        $output->writeln(sprintf(
            '%d ok, %s, %s',
            $counts[CheckResult::OK],
            $plural($counts[CheckResult::WARN], 'warning'),
            $plural($counts[CheckResult::FAIL], 'problem'),
        ));
    }

    private static function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
