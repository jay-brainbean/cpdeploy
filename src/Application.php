<?php

declare(strict_types=1);

namespace Cpdeploy;

use Cpdeploy\Commands\CheckCommand;
use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Registers commands and global options, refuses root (CLI-01), prepares
 * ~/cpdeploy (LAY-02/03) and renders every error in the §13 format.
 */
final class Application extends ConsoleApplication
{
    /** Commands that must work before ~/cpdeploy exists and never create it. */
    private const NO_BOOTSTRAP = ['list', 'help', 'completion', '_complete'];

    public function __construct(private readonly Services $services, private readonly bool $allowRoot = false)
    {
        parent::__construct('cpdeploy', Version::get());
        $this->setCatchExceptions(false);
        $this->setAutoExit(false);

        $this->add(new CheckCommand($services));
    }

    public function getLongVersion(): string
    {
        $phar = \Phar::running(false);

        return sprintf(
            'cpdeploy %s · PHP %s (%s)%s',
            Version::get(),
            PHP_VERSION,
            PHP_BINARY,
            $phar !== '' ? ' · ' . $phar : ' · from source',
        );
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('yes', 'y', InputOption::VALUE_NONE, 'Accept every default answer and confirmation'));
        $definition->addOption(new InputOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output, on commands that support it'));

        return $definition;
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        umask(0022);

        try {
            $this->configureColour($input, $output);

            if ($input->hasParameterOption(['--version', '-V'], true)) {
                $output->writeln($this->getLongVersion());

                return 0;
            }

            if ($this->services->system()->isRoot() && !$this->allowRoot) {
                throw new CpdeployException(
                    ErrorCode::ROOT,
                    'cpdeploy must run as the cPanel user, not root (files would be owned by root and the site would break)',
                    'Run: su - <user> -s /bin/bash -c cpdeploy',
                );
            }

            $name = $this->getCommandName($input);
            if ($name !== null && !in_array($name, self::NO_BOOTSTRAP, true)) {
                $this->bootstrap($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
            }
            $this->services->signals()->install();

            return parent::doRun($input, $output);
        } catch (CpdeployException $e) {
            $this->renderError($e, $input, $output);

            return $e->exitCode();
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface $e) {
            // Usage errors from Symfony (unknown option, missing argument): exit 2.
            $this->renderError(new CpdeployException(ErrorCode::USAGE, $e->getMessage(), 'Run: cpdeploy help <command>'), $input, $output);

            return 2;
        } catch (Throwable $e) {
            $crash = $this->writeCrashLog($e);
            $this->renderError(new CpdeployException(
                ErrorCode::INTERNAL,
                'Unexpected error: ' . $e->getMessage(),
                'This is a bug in cpdeploy. Please report it with the log.',
                logPath: $crash,
            ), $input, $output);

            return 1;
        }
    }

    /**
     * LAY-02: create ~/cpdeploy with the right modes; re-apply the modes of the
     * root, secrets/, config.yml and the token on every start. LAY-03: clean tmp/.
     */
    private function bootstrap(OutputInterface $errors): void
    {
        $paths = $this->services->paths();
        $fs = $this->services->fs();
        $first = !is_dir($paths->root());

        foreach ($paths->skeleton() as $dir => $mode) {
            if (!is_dir($dir)) {
                $fs->ensureDir($dir, $mode);
            }
        }
        @chmod($paths->root(), Paths::MODE_ROOT);
        @chmod($paths->secretsDir(), Paths::MODE_PRIVATE_DIR);
        foreach ([$paths->configFile(), $paths->tokenFile()] as $secret) {
            if (is_file($secret)) {
                @chmod($secret, Paths::MODE_SECRET_FILE);
            }
        }
        // Load (and validate) config.yml on every start, so a broken file is reported
        // up front rather than half-way through an operation.
        $config = $this->services->config();
        if ($first || !is_file($paths->configFile())) {
            $config->save($paths->configFile(), $fs);
        }
        foreach ($config->warnings as $warning) {
            $errors->writeln(sprintf('<fg=yellow>%s</> config.yml: %s', $this->services->theme()->symbol('warn'), $warning));
        }
        $fs->cleanTmp();
    }

    private function configureColour(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasParameterOption(['--no-ansi'], true)) {
            return;
        }
        $noColor = $this->services->environment()->get('NO_COLOR');
        if ($noColor !== null) {
            $output->setDecorated(false);
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->setDecorated(false);
            }
        }
    }

    /**
     * §13 format:
     *   ✗ <message>
     *     Live site: <affected / not changed>
     *     Fix: <hint>
     *     Log: <path>
     * With --json, stdout also gets {"schema":1,"error":{…}} so it stays parseable.
     */
    private function renderError(CpdeployException $e, InputInterface $input, OutputInterface $output): void
    {
        $masker = $this->services->masker();
        $theme = $this->services->theme();
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $lines = [sprintf('<fg=red>%s</> %s', $theme->symbol('fail'), $this->escape($masker->mask($e->getMessage())))];
        $lines[] = '  Live site: ' . ($e->liveAffected ? '<fg=yellow>affected — see the message</>' : 'not changed');
        if ($e->hint !== '') {
            $lines[] = '  Fix: ' . $this->escape($masker->mask($e->hint));
        }
        if ($e->logPath !== null) {
            $lines[] = '  Log: ' . $e->logPath;
        }
        $err->writeln($lines);

        if ($input->hasParameterOption(['--json'], true)) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'error' => [
                    'code' => $e->errorCode->value,
                    'message' => $masker->mask($e->getMessage()),
                    'hint' => $masker->mask($e->hint),
                    'live_affected' => $e->liveAffected,
                    'exit_code' => $e->exitCode(),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        }
    }

    private function writeCrashLog(Throwable $e): ?string
    {
        try {
            $paths = $this->services->paths();
            if (!is_dir($paths->root())) {
                return null;
            }
            $file = $paths->root() . '/crash.log';
            $text = sprintf(
                "cpdeploy %s crashed at %s\nPHP %s (%s)\n\n%s\n",
                Version::get(),
                $this->services->clock()->iso(),
                PHP_VERSION,
                PHP_BINARY,
                (string) $e,
            );
            $this->services->fs()->writeAtomic($file, $this->services->masker()->mask($text), Paths::MODE_SECRET_FILE);

            return $file;
        } catch (Throwable) {
            return null;
        }
    }

    private function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
