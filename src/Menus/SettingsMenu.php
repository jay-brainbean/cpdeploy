<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Config\Schema\GlobalSchema;
use Cpdeploy\Git\HostKeyRefresh;
use Cpdeploy\GitHub\GitHubUser;
use Cpdeploy\GitHub\TokenService;
use Cpdeploy\Ui\Format;
use Cpdeploy\Version;
use Phar;

/**
 * Settings (§9.6): the GitHub token, defaults for new sites, timeouts,
 * display, GitHub host keys, About, and updates. Changes go to config.yml,
 * validated (§8.3), through the same services as the commands (ARC-03).
 */
final class SettingsMenu
{
    private const TIMEOUTS = [
        'git' => 'git fetch and clone',
        'composer' => 'composer install',
        'node_install' => 'npm ci / install',
        'node_build' => 'npm run build',
        'artisan' => 'artisan commands',
        'migrate' => 'migrations',
        'custom' => 'custom commands',
        'http' => 'HTTP requests',
    ];

    private const UI = ['auto' => 'auto (from the terminal)', 'on' => 'on', 'off' => 'off'];

    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(): void
    {
        while (true) {
            $this->ctx->title('Settings');
            $choice = $this->ctx->choose('Settings', [
                'token' => 'GitHub token',
                'defaults' => 'Defaults for new sites',
                'timeouts' => 'Timeouts',
                'display' => 'Display',
                'hostkeys' => 'Refresh GitHub host keys',
                'about' => 'About',
                'update' => 'Check for updates',
            ]);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(fn () => $this->open((string) $choice));
        }
    }

    private function open(string $choice): void
    {
        match ($choice) {
            'token' => $this->token(),
            'defaults' => $this->defaults(),
            'timeouts' => $this->timeouts(),
            'display' => $this->display(),
            'hostkeys' => $this->hostKeys(),
            'about' => $this->about(),
            'update' => $this->update(),
            default => null,
        };
    }

    private function token(): void
    {
        $tokens = $this->ctx->services->tokenService();
        $this->ctx->title('Settings', 'GitHub token');
        $this->ctx->line($tokens->has() ? 'A token is set.' : 'No token is set: deploy keys are added by hand.');
        $choice = $this->ctx->choose('GitHub token', array_filter([
            'set' => $tokens->has() ? 'Replace the token' : 'Set a token',
            'test' => $tokens->has() ? 'Test the token' : null,
            'remove' => $tokens->has() ? 'Remove the token' : null,
        ]));
        if ($choice === 'set') {
            foreach (TokenService::GUIDANCE as $line) {
                $this->ctx->line($line);
            }
            $token = trim($this->ctx->asker->password('GitHub token', 'The input is hidden; it is checked with GitHub before it is saved'));
            if ($token === '') {
                return;
            }
            $this->ctx->services->masker()->add($token);
            $user = $tokens->set($token);
            $this->ctx->ok("Token saved for GitHub user {$user->login}");
            $this->describe($user);
        } elseif ($choice === 'test') {
            $user = $tokens->test();
            $this->ctx->ok("The token works (GitHub user {$user->login})");
            $this->describe($user);
        } elseif ($choice === 'remove') {
            if ($this->ctx->asker->confirm('Remove the GitHub token? Deploy keys already added stay on GitHub', false)) {
                $tokens->remove();
                $this->ctx->ok('GitHub token removed');
            }
        } else {
            return;
        }
        $this->ctx->pause();
    }

    private function describe(GitHubUser $user): void
    {
        $now = $this->ctx->services->clock()->now();
        $this->ctx->line($user->expiresAt === null ? 'Expires: never' : 'Expires: ' . Format::local($user->expiresAt) . ' (' . Format::relative($user->expiresAt, $now) . ')');
        if ($user->expiresWithin($now)) {
            $this->ctx->warn('The token expires in under 14 days. Create a new one and set it here.');
        }
        if ($user->classic) {
            $this->ctx->warn('This is a classic token: a fine-grained one with Administration: Read and write is safer.');
        }
    }

    private function defaults(): void
    {
        while (true) {
            $config = $this->ctx->services->config();
            $this->ctx->title('Settings', 'Defaults for new sites');
            $choice = $this->ctx->choose('Defaults for new sites', [
                'sites' => 'Sites folder: ~/' . $config->sitesDir() . '/<domain>',
                'keep' => 'Keep last ' . $config->defaultKeepReleases() . ' releases',
                'sync' => 'Set the domain\'s MultiPHP version at go-live: ' . ($config->defaultFlag('sync_multiphp') ? 'yes' : 'no'),
                'health' => 'Health check after go-live: ' . ($config->defaultFlag('health_check') ? 'on' : 'off'),
            ]);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(function () use ($choice, $config): void {
                $services = $this->ctx->services;
                match ($choice) {
                    'sites' => $services->changeConfig(['sites_dir' => trim(preg_replace('#^~/#', '', trim($this->ctx->asker->text(
                        'Folder for new sites, inside your home folder (existing sites stay where they are)',
                        '~/' . $config->sitesDir(),
                        '~/cpdeploy_sites',
                        required: true,
                    ))) ?? '', '/')]),
                    'keep' => $services->changeConfig(['defaults.keep_releases' => (int) $this->ctx->asker->text('Keep how many releases? (1–50, counting the live one)', (string) $config->defaultKeepReleases(), required: true, validate: static fn (string $v): ?string => ctype_digit($v) && (int) $v >= 1 && (int) $v <= 50 ? null : 'A whole number from 1 to 50')]),
                    'sync' => $services->changeConfig(['defaults.sync_multiphp' => $this->ctx->asker->confirm("Set the domain's MultiPHP version at go-live for new sites?", $config->defaultFlag('sync_multiphp'))]),
                    'health' => $services->changeConfig(['defaults.health_check' => $this->ctx->asker->confirm('Check new sites after each go-live?', $config->defaultFlag('health_check'))]),
                    default => null,
                };
            });
        }
    }

    private function timeouts(): void
    {
        while (true) {
            $config = $this->ctx->services->config();
            $this->ctx->title('Settings', 'Timeouts');
            $options = [];
            foreach (self::TIMEOUTS as $key => $label) {
                $options[$key] = str_pad($label . ' ', 30, '.') . ' ' . Format::duration((float) $config->timeout($key));
            }
            $choice = $this->ctx->choose('Select a timeout to change', $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $key = (string) $choice;
            $this->ctx->attempt(function () use ($key, $config): void {
                $seconds = $this->ctx->asker->text(self::TIMEOUTS[$key] . ': seconds (5–86400)', (string) $config->timeout($key), required: true, validate: static fn (string $v): ?string => ctype_digit($v) && (int) $v >= 5 && (int) $v <= 86400 ? null : 'A number of seconds from 5 to 86400');
                $this->ctx->services->changeConfig(['timeouts.' . $key => (int) $seconds]);
            });
        }
    }

    private function display(): void
    {
        while (true) {
            $config = $this->ctx->services->config();
            $this->ctx->title('Settings', 'Display');
            $editor = $config->editor();
            $choice = $this->ctx->choose('Display (applies the next time cpdeploy starts)', [
                'unicode' => 'Symbols (✓ ⚠ ✗): ' . self::UI[self::uiValue($config->uiFlag('unicode'))],
                'color' => 'Colour: ' . self::UI[self::uiValue($config->uiFlag('color'))],
                'editor' => 'Editor: ' . ($editor !== '' ? $editor : '$VISUAL → $EDITOR → nano → vi'),
            ]);
            if ($choice === MenuContext::BACK) {
                return;
            }
            $this->ctx->attempt(function () use ($choice, $config, $editor): void {
                if ($choice === 'editor') {
                    $value = trim($this->ctx->asker->text('Editor command (empty = $VISUAL → $EDITOR → nano → vi)', $editor, 'nano'));
                    $this->ctx->services->changeConfig(['ui.editor' => $value]);

                    return;
                }
                $key = (string) $choice;
                $value = (string) $this->ctx->asker->select($key === 'unicode' ? 'Symbols' : 'Colour', self::UI, self::uiValue($config->uiFlag($key)));
                $this->ctx->services->changeConfig(['ui.' . $key => match ($value) {
                    'on' => true,
                    'off' => false,
                    default => 'auto',
                }]);
            });
        }
    }

    private function hostKeys(): void
    {
        $refresh = $this->ctx->services->hostKeyRefresh();
        $this->ctx->title('Settings', 'GitHub host keys');
        [$text, $found] = $refresh->fetch();
        foreach (HostKeyRefresh::lines($found) as $line) {
            $this->ctx->line($line);
        }
        if ($this->ctx->asker->confirm('Replace ~/cpdeploy/known_hosts with these keys?', false)) {
            $refresh->apply($text);
            $this->ctx->ok('~/cpdeploy/known_hosts replaced');
        } else {
            $this->ctx->line('Nothing was changed.');
        }
        $this->ctx->pause();
    }

    private function about(): void
    {
        $services = $this->ctx->services;
        $paths = $services->paths();
        $phar = Phar::running(false);
        $this->ctx->title('Settings', 'About');
        foreach ([
            'Version' => Version::get(),
            'Tool PHP' => PHP_VERSION . ' (' . PHP_BINARY . ')',
            'Phar' => $phar !== '' ? $phar : 'not a phar (running from the source folder)',
            'Config' => $paths->configFile(),
            'Sites' => $paths->sitesDir(),
            'Site files' => $paths->sitesFilesRoot($services->config()->sitesDir()),
            'Known hosts' => $paths->knownHosts(),
            'Update repo' => $services->config()->updateRepo() . ($services->config()->updateRepo() === GlobalSchema::DEFAULT_UPDATE_REPO ? '' : ' (changed in config.yml)'),
        ] as $label => $value) {
            $this->ctx->line(sprintf('%-12s %s', $label, $value));
        }
        $this->ctx->pause();
    }

    private function update(): void
    {
        $updater = $this->ctx->services->selfUpdate();
        $this->ctx->title('Settings', 'Check for updates');
        $latest = $updater->latest();
        if ($latest === null) {
            $this->ctx->line("No releases of cpdeploy were found in {$updater->repo()}.");
        } elseif (!$updater->isNewer($latest)) {
            $this->ctx->ok("cpdeploy {$updater->current()} is up to date");
        } elseif ($this->ctx->asker->confirm("cpdeploy {$latest->version} is available (you have {$updater->current()}). Update now?", true)) {
            $updater->update($latest, $this->ctx->reporter);
            $this->ctx->ok("Updated to {$latest->version}. The new version is used the next time cpdeploy starts.");
            foreach (array_slice(explode("\n", trim($latest->notes)), 0, 15) as $line) {
                $this->ctx->line($line);
            }
        }
        $this->ctx->pause();
    }

    private static function uiValue(?bool $flag): string
    {
        return match ($flag) {
            true => 'on',
            false => 'off',
            null => 'auto',
        };
    }
}
