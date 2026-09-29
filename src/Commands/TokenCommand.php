<?php

declare(strict_types=1);

namespace Cpdeploy\Commands;

use Cpdeploy\GitHub\GitHubUser;
use Cpdeploy\GitHub\TokenService;
use Cpdeploy\Services;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\Format;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cpdeploy token set [--stdin] | test | remove` (§10.2, §7.5).
 */
#[AsCommand(name: 'token', description: 'Set, test or remove the optional GitHub token')]
final class TokenCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'set, test or remove')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the token from standard input (set)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = $this->services->tokenService();
        $theme = $this->services->theme();

        switch ($input->getArgument('action')) {
            case 'set':
                $token = $this->readToken($input, $output);
                $user = $tokens->set($token);
                $output->writeln(sprintf('<fg=green>%s</> Token saved for GitHub user %s', $theme->symbol('ok'), $user->login));
                $this->describe($user, $output);

                return 0;

            case 'test':
                $user = $tokens->test();
                $output->writeln(sprintf('<fg=green>%s</> The token works (GitHub user %s)', $theme->symbol('ok'), $user->login));
                $this->describe($user, $output);

                return 0;

            case 'remove':
                if (!$tokens->remove()) {
                    $output->writeln('No GitHub token was set.');

                    return 0;
                }
                $output->writeln(sprintf('<fg=green>%s</> GitHub token removed. Deploy keys already added stay on GitHub.', $theme->symbol('ok')));

                return 0;

            default:
                throw new CpdeployException(ErrorCode::USAGE, 'Unknown action: ' . (string) $input->getArgument('action'), 'Use: cpdeploy token set | test | remove');
        }
    }

    /**
     * The token never comes from the command line (SEC-03): stdin, or a hidden prompt.
     */
    private function readToken(InputInterface $input, OutputInterface $output): string
    {
        if ($input->getOption('stdin') === true || !$this->services->isInteractive($input)) {
            $line = fgets(STDIN);
            if ($line === false || trim($line) === '') {
                throw new CpdeployException(ErrorCode::NEEDS_ANSWER, 'No token was given on standard input', 'Run: cpdeploy token set --stdin < token.txt, or run it in a terminal.');
            }

            return trim($line);
        }
        foreach (TokenService::GUIDANCE as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        return $this->services->asker($input)->password('GitHub token', 'The input is hidden');
    }

    private function describe(GitHubUser $user, OutputInterface $output): void
    {
        $theme = $this->services->theme();
        $now = $this->services->clock()->now();
        if ($user->expiresAt === null) {
            $output->writeln('  Expires: never');
        } else {
            $output->writeln(sprintf('  Expires: %s (%s)', Format::local($user->expiresAt), Format::relative($user->expiresAt, $now)));
            if ($user->expiresWithin($now)) {
                $output->writeln(sprintf('<fg=yellow>%s</> The token expires in under 14 days. Create a new one and run: cpdeploy token set', $theme->symbol('warn')));
            }
        }
        if ($user->classic) {
            $output->writeln(sprintf('<fg=yellow>%s</> This is a classic token: it grants far more access than cpdeploy needs. A fine-grained token with Administration: Read and write on your repos is safer.', $theme->symbol('warn')));
        }
    }
}
