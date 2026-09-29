<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\Env\EnvFile;
use Cpdeploy\Env\EnvManager;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\LineDiff;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy env <site> list [--reveal] | get KEY | set KEY=VALUE [--apply] |
 * unset KEY [--apply] | edit | apply | restore [<backup>]` (§10.2, §7.12).
 */
#[AsCommand(name: 'env', description: 'Show or change a site\'s .env')]
final class EnvCommand extends SiteCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::REQUIRED, 'Site name')
            ->addArgument('action', InputArgument::OPTIONAL, 'list, get, set, unset, edit, apply or restore', 'list')
            ->addArgument('value', InputArgument::OPTIONAL, 'KEY (get, unset), KEY=VALUE (set; VALUE "-" reads stdin) or a backup name (restore)')
            ->addOption('reveal', null, InputOption::VALUE_NONE, 'Show secret values (list)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the change to the live site (php artisan optimize)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $this->site($input);
        $env = $this->services->envManager();
        $action = (string) $input->getArgument('action');
        $value = $input->getArgument('value');
        $value = is_string($value) ? $value : '';
        $theme = $this->services->theme();
        $reporter = $this->services->reporter($input, $output);
        $asker = $this->services->asker($input);

        switch ($action) {
            case 'list':
                $rows = $env->listing($site, $input->getOption('reveal') === true);
                if ($rows === []) {
                    $output->writeln('.env has no variables.');

                    return 0;
                }
                $table = new Table($output);
                $table->setHeaders(['Key', 'Value']);
                foreach ($rows as [$key, $shown]) {
                    $table->addRow([$key, $shown === EnvManager::MASK ? $theme->symbol('mask') : str_replace("\n", '\n', $shown)]);
                }
                $table->render();

                return 0;

            case 'get':
                $key = $this->key($value, $site);
                $got = $env->read($site)->get($key);
                if ($got === null) {
                    throw new CpdeployException(ErrorCode::USAGE, ".env has no {$key}", "List the keys: cpdeploy env {$site} list");
                }
                $output->writeln($got, OutputInterface::OUTPUT_RAW);

                return 0;

            case 'set':
                if (!str_contains($value, '=')) {
                    throw new CpdeployException(ErrorCode::USAGE, 'Pass KEY=VALUE', "Example: cpdeploy env {$site} set APP_DEBUG=false (VALUE - reads it from stdin)");
                }
                [$key, $new] = explode('=', $value, 2);
                $key = $this->key($key, $site);
                if ($new === '-') {
                    $new = rtrim((string) stream_get_contents(STDIN), "\r\n");
                }
                $env->set($site, $key, $new);
                $output->writeln(sprintf('<fg=green>%s</> %s saved (the previous .env is in env-backups)', $theme->symbol('ok'), $key));
                $this->maybeApply($site, $input, $output);

                return 0;

            case 'unset':
                $key = $this->key($value, $site);
                $env->unset($site, $key);
                $output->writeln(sprintf('<fg=green>%s</> %s removed (the previous .env is in env-backups)', $theme->symbol('ok'), $key));
                $this->maybeApply($site, $input, $output);

                return 0;

            case 'edit':
                if (!$asker->interactive()) {
                    throw new CpdeployException(ErrorCode::USAGE, 'env edit needs a terminal', "Use: cpdeploy env {$site} set KEY=VALUE");
                }
                $current = $env->exists($site) ? $env->read($site)->toString() : '';
                $edited = $this->services->editor()->edit($current, 'env', static function (string $text): ?string {
                    try {
                        EnvFile::parse($text);

                        return null;
                    } catch (CpdeployException $e) {
                        return $e->getMessage();
                    }
                }, $asker, $reporter);
                if ($edited === null) {
                    $output->writeln('Nothing was changed.');

                    return 0;
                }
                $env->save($site, $edited, 'edited in the editor');
                $output->writeln(sprintf('<fg=green>%s</> .env saved (the previous one is in env-backups)', $theme->symbol('ok')));
                $this->maybeApply($site, $input, $output);

                return 0;

            case 'apply':
                $env->apply($site, $reporter);

                return 0;

            case 'restore':
                if ($value === '') {
                    $backups = $env->backups($site);
                    $output->writeln($backups === [] ? 'No .env backups yet.' : implode("\n", $backups));

                    return 0;
                }
                $current = $env->exists($site) ? $env->read($site)->toString() : '';
                $diff = LineDiff::lines($current, $env->backupContent($site, $value)) ?? [];
                $confirmed = $input->getOption('yes') === true;
                if (!$confirmed) {
                    if (!$asker->interactive()) {
                        throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'This needs answers: --yes (to confirm the restore)', 'Run again with --yes.');
                    }
                    foreach ($diff as $line) {
                        $output->writeln('  ' . $this->services->masker()->mask($line), OutputInterface::OUTPUT_RAW);
                    }
                    $confirmed = $asker->confirm("Restore {$value}?", false);
                }
                if (!$confirmed) {
                    $output->writeln('Nothing was changed.');

                    return 0;
                }
                $env->restore($site, $value);
                $output->writeln(sprintf('<fg=green>%s</> %s restored', $theme->symbol('ok'), $value));
                $this->maybeApply($site, $input, $output);

                return 0;

            default:
                throw new CpdeployException(ErrorCode::USAGE, "Unknown action: {$action}", "Use: cpdeploy env {$site} list | get | set | unset | edit | apply | restore");
        }
    }

    /**
     * ENV-08: --apply, or asked on a terminal (default Yes); otherwise a hint.
     */
    private function maybeApply(string $site, InputInterface $input, OutputInterface $output): void
    {
        $config = $this->services->sites()->load($site);
        if (!$config->isLaravel() || $this->services->releases()->liveId($site) === null) {
            return;
        }
        $asker = $this->services->asker($input);
        $apply = $input->getOption('apply') === true || $input->getOption('yes') === true
            || ($asker->interactive() && $asker->confirm('Apply to the live site now? (runs php artisan optimize on the live release)', true));
        if ($apply) {
            $this->services->envManager()->apply($site, $this->services->reporter($input, $output));

            return;
        }
        $output->writeln("The live site keeps its cached config until the next deploy, or: cpdeploy env {$site} apply");
    }

    private function key(string $key, string $site): string
    {
        if (preg_match(EnvFile::KEY_PATTERN, $key) !== 1) {
            throw new CpdeployException(ErrorCode::USAGE, 'Which variable? Pass its name, e.g. APP_DEBUG', "List them with: cpdeploy env {$site} list");
        }

        return $key;
    }
}
