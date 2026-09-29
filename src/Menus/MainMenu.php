<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Commands\CheckCommand;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Log;
use Cpdeploy\Ui\Format;
use Cpdeploy\Version;
use Cpdeploy\Wizard\AddSiteWizard;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;

/**
 * The main menu (§9.2): the site table, banners (UIG-03), and the main actions.
 */
final class MainMenu
{
    /** @var list<string>|null token banner lines, checked once per menu session */
    private ?array $tokenBanner = null;

    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(bool $firstRun = false): void
    {
        if ($firstRun) {
            $this->welcome();
        }
        while (true) {
            $sites = $this->ctx->services->siteStatus()->all()['sites'];
            /** @var list<array<string, mixed>> $sites */
            $this->header($sites);
            $options = $this->banners($sites);
            if ($sites === []) {
                $this->ctx->line('No sites yet. Add your first site to get started.');
                $options += ['add' => 'Add a new site', 'check' => 'Server check', 'settings' => 'Settings', 'quit' => 'Quit'];
            } else {
                $options += [
                    'deploy' => 'Deploy a site',
                    'add' => 'Add a new site',
                    'manage' => 'Manage a site',
                    'logs' => 'Logs & history',
                    'check' => 'Server check',
                    'settings' => 'Settings',
                    'quit' => 'Quit',
                ];
            }
            $choice = (string) $this->ctx->asker->select('What would you like to do?', $options, $sites === [] ? 'add' : 'deploy');
            if ($choice === 'quit') {
                return;
            }
            $this->ctx->attempt(fn () => $this->open($choice, $sites));
        }
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function open(string $choice, array $sites): void
    {
        $names = array_map(static fn (array $s): string => (string) $s['name'], $sites);
        if (str_starts_with($choice, 'recover:')) {
            $site = substr($choice, 8);
            $result = $this->ctx->services->recovery()->recover($site, true, $this->ctx->asker, $this->ctx->reporter);
            foreach ($result->checks as $check) {
                $this->ctx->warn($check);
            }
            $this->ctx->pause();

            return;
        }
        if (str_starts_with($choice, 'up:')) {
            $this->ctx->services->laravelTools()->up(substr($choice, 3), $this->ctx->reporter);

            return;
        }
        switch ($choice) {
            case 'deploy':
                $site = $this->site($names, 'Deploy which site?');
                if ($site !== null) {
                    (new DeployScreen($this->ctx))->deploy($site);
                }
                break;
            case 'manage':
                $site = $this->site($names, 'Manage which site?');
                if ($site !== null) {
                    (new ManageSiteMenu($this->ctx))->run($site);
                }
                break;
            case 'logs':
                (new LogsMenu($this->ctx))->all();
                break;
            case 'check':
                $this->ctx->title('Server check');
                $this->ctx->reporter->start('Checking the server');
                $groups = $this->ctx->services->serverCheck()->run();
                $this->ctx->reporter->succeed('');
                CheckCommand::render($groups, $this->ctx->output, $this->ctx->theme, $this->ctx->services->masker());
                $this->ctx->pause();
                break;
            case 'add':
                (new AddSiteWizard($this->ctx))->run();
                break;
            case 'settings':
                $this->ctx->title('Settings');
                $this->ctx->line('The settings screen is not available in this version yet. Meanwhile:');
                $this->ctx->line('  GitHub token: cpdeploy token set | test | remove');
                $this->ctx->line('  Everything else: ~/cpdeploy/config.yml (checked every time cpdeploy starts)');
                $this->ctx->pause();
                break;
        }
    }

    /**
     * §9.2: one site → straight to it; up to 8 → select; more → search.
     *
     * @param list<string> $names
     */
    private function site(array $names, string $label): ?string
    {
        if (count($names) === 1) {
            return $names[0];
        }
        $choice = $this->ctx->pick($label, array_combine($names, $names));

        return $choice === MenuContext::BACK ? null : $choice;
    }

    /**
     * "cpdeploy 1.0.0 · user@host · 3 sites" and the site table.
     *
     * @param list<array<string, mixed>> $sites
     */
    private function header(array $sites): void
    {
        $system = $this->ctx->services->system();
        $this->ctx->output->writeln('');
        $this->ctx->output->writeln(sprintf(
            '<options=bold>cpdeploy %s</> · %s@%s · %d site%s',
            Version::get(),
            $system->userName(),
            $system->hostName(),
            count($sites),
            count($sites) === 1 ? '' : 's',
        ));
        if ($sites === []) {
            return;
        }
        $table = new Table($this->ctx->output);
        $table->setStyle((new TableStyle())->setHorizontalBorderChars('')->setVerticalBorderChars(' ')->setDefaultCrossingChar(''));
        $table->setHeaders(['SITE', 'DOMAIN', 'LIVE', 'LAST']);
        foreach ($sites as $site) {
            $live = is_array($site['live'] ?? null) ? $site['live'] : null;
            $table->addRow([
                (string) $site['name'],
                Format::truncate((string) ($site['domain'] ?? ''), 28),
                $live !== null ? substr((string) $live['commit'], 0, 7) : '—',
                $this->last($site),
            ]);
        }
        $table->render();
    }

    /**
     * The LAST column (§9.2).
     *
     * @param array<string, mixed> $site
     */
    private function last(array $site): string
    {
        $t = $this->ctx->theme;
        if (($site['locked'] ?? false) === true) {
            return $t->symbol('ellipsis') . ' deploying now';
        }
        if (($site['interrupted'] ?? false) === true) {
            return $t->symbol('warn') . ' interrupted, recover';
        }
        if (($site['maintenance'] ?? false) === true) {
            return $t->symbol('warn') . ' maintenance on';
        }
        foreach (array_reverse(Log::readHistory($this->ctx->services->paths()->history((string) $site['name']), 50)) as $entry) {
            $when = $this->ctx->when($entry['ts'] ?? null);
            $result = $entry['result'] ?? '';
            if (($entry['action'] ?? '') === 'rollback' && $result === 'success') {
                return $t->symbol('rolled_back') . ' rolled back ' . $when;
            }
            if (($entry['action'] ?? '') === 'deploy' && in_array($result, ['success', 'warning'], true)) {
                return $t->symbol('ok') . ' deployed ' . $when;
            }
            if (($entry['action'] ?? '') === 'deploy' && $result === 'failed') {
                return $t->symbol('fail') . ' deploy failed ' . $when;
            }
        }

        return $site['live'] === null ? $t->symbol('dash') . ' not deployed yet' : $t->symbol('ok') . ' live';
    }

    /**
     * UIG-03: interrupted operations (→ Recover now), maintenance (→ Turn off),
     * then the GitHub token. Returns the selectable ones as menu options.
     *
     * @param list<array<string, mixed>> $sites
     * @return array<string, string>
     */
    private function banners(array $sites): array
    {
        $options = [];
        $warn = $this->ctx->theme->symbol('warn');
        $arrow = $this->ctx->theme->symbol('arrow');
        foreach ($sites as $site) {
            if (($site['interrupted'] ?? false) === true) {
                $state = @file_get_contents($this->ctx->services->paths()->stateFile((string) $site['name']));
                $data = is_string($state) ? json_decode($state, true) : null;
                $operation = is_array($data) && is_string($data['operation'] ?? null) ? $data['operation'] : 'deploy';
                $options['recover:' . $site['name']] = "{$warn} A {$operation} of {$site['name']} was interrupted. {$arrow} Recover now";
            }
        }
        foreach ($sites as $site) {
            if (($site['maintenance'] ?? false) === true && ($site['interrupted'] ?? false) !== true) {
                $options['up:' . $site['name']] = "{$warn} {$site['name']} is in maintenance mode {$arrow} Turn off";
            }
        }
        foreach ($this->tokenBanner() as $line) {
            $this->ctx->warn($line);
        }

        return $options;
    }

    /**
     * An invalid token, or one expiring within 14 days. Checked once per session
     * (it is an API call); a network problem shows nothing.
     *
     * @return list<string>
     */
    private function tokenBanner(): array
    {
        if ($this->tokenBanner !== null) {
            return $this->tokenBanner;
        }
        $this->tokenBanner = [];
        $tokens = $this->ctx->services->tokenService();
        if (!$tokens->has()) {
            return [];
        }
        try {
            $user = $tokens->test();
            if ($user->expiresWithin($this->ctx->services->clock()->now())) {
                $this->tokenBanner[] = sprintf('The GitHub token of %s expires %s — replace it: cpdeploy token set', $user->login, $user->expiresAt?->format('Y-m-d') ?? 'soon');
            }
        } catch (CpdeployException $e) {
            if ($e->errorCode === ErrorCode::TOKEN_INVALID) {
                $this->tokenBanner[] = 'The GitHub token is invalid or expired — replace it: cpdeploy token set';
            }
        }

        return $this->tokenBanner;
    }

    /**
     * UIG-02: first run — welcome, then a quick check showing only ⚠ and ✗.
     */
    private function welcome(): void
    {
        $this->ctx->title('Welcome to cpdeploy');
        $this->ctx->line('A quick look at this server first:');
        $groups = $this->ctx->services->serverCheck()->run([ServerCheck::GROUP_TOOL, ServerCheck::GROUP_PROGRAMS, ServerCheck::GROUP_CPANEL]);
        CheckCommand::render($groups, $this->ctx->output, $this->ctx->theme, $this->ctx->services->masker(), true);
    }
}
