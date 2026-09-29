<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy php <site> [version] [--no-sync] [--redeploy|--no-redeploy]
 * [--switch-now]` (§10.2, §9.5.4). Without a version: what the site, the domain
 * and the live release use.
 */
#[AsCommand(name: 'php', description: 'Show or change the PHP version a site uses')]
final class PhpCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site name')
            ->addArgument('version', InputArgument::OPTIONAL, 'New version, e.g. 8.3 (or ea-php83 / alt-php83)')
            ->addOption('no-sync', null, InputOption::VALUE_NONE, 'Don\'t set the domain\'s MultiPHP version at go-live')
            ->addOption('redeploy', null, InputOption::VALUE_NONE, 'Deploy now with the new PHP (rebuilds vendor/)')
            ->addOption('no-redeploy', null, InputOption::VALUE_NONE, 'Only save the setting; it takes effect at the next deploy')
            ->addOption('switch-now', null, InputOption::VALUE_NONE, 'Set the domain\'s PHP right away, without redeploying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $php = $this->services->phpChange();
        $version = $input->getArgument('version');
        if (!is_string($version) || $version === '') {
            foreach ($php->overview($site) as $label => $value) {
                $output->writeln(sprintf('%-14s %s', $label . ':', $value));
            }
            $installed = array_map(static fn (PhpInstall $i): string => $i->majorMinor() . ' (' . $i->family . ')', $php->installs());
            $output->writeln(sprintf('%-14s %s', 'Installed:', $installed === [] ? 'none found' : implode(', ', $installed)));

            return 0;
        }

        [$majorMinor, $family] = self::parseVersion($version);
        $config = $php->set($site, $majorMinor, $family, $input->getOption('no-sync') === true ? false : null);
        $theme = $this->services->theme();
        $output->writeln(sprintf('<fg=green>%s</> %s now uses PHP %s (%s)', $theme->symbol('ok'), $site, $config->phpVersion(), $config->phpFamily()));

        $asker = $this->services->asker($input);
        $live = $this->services->releases()->liveId($site) !== null;
        $redeploy = match (true) {
            $input->getOption('redeploy') === true => true,
            $input->getOption('no-redeploy') === true || !$live => false,
            $asker->interactive() => $asker->confirm(sprintf('Redeploy now with PHP %s? (recommended: rebuilds vendor/ for %1$s; the domain switches to %1$s at go-live)', $config->phpVersion()), true),
            default => false,
        };
        if ($redeploy) {
            $result = $this->services->deployer()->deploy($site, new DeployFlags(composer: DeployFlags::YES, force: true, yes: $input->getOption('yes') === true), $asker, $this->services->reporter($input, $output));

            return DeployCommand::report($result, $output, $theme);
        }

        if ($live && $config->syncMultiPhp()) {
            $now = $input->getOption('switch-now') === true
                || ($asker->interactive() && $asker->confirm(sprintf('Also switch the domain\'s PHP right now? The live release was built with another PHP — switching without rebuilding can break the site.'), false));
            if ($now) {
                $served = $php->switchNow($site, $this->services->reporter($input, $output));
                $output->writeln('Served PHP: ' . ($served ?? 'unknown (the site did not return a version; it may be behind authentication or a firewall)'));

                return 0;
            }
        }
        $output->writeln('It takes effect at the next deploy: cpdeploy deploy ' . $site);

        return 0;
    }

    /**
     * "8.3" → [8.3, null]; "ea-php83" / "alt-php83" → [8.3, ea|alt].
     *
     * @return array{0: string, 1: ?string}
     */
    public static function parseVersion(string $version): array
    {
        $tag = PhpInstall::parseTag(strtolower(trim($version)));
        if ($tag !== null) {
            return [$tag[1], $tag[0]];
        }
        if (preg_match('/^(\d+)\.(\d+)$/', trim($version), $m) === 1) {
            return [$m[1] . '.' . $m[2], null];
        }
        throw new CpdeployException(ErrorCode::USAGE, "{$version} isn't a PHP version", 'Use major.minor, e.g. 8.3 (or ea-php83 / alt-php83).');
    }
}
