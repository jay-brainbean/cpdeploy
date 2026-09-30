<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Deploy\DeployFlags;
use Cpdeploy\Runtime\ComposerAuth;
use Cpdeploy\Support\Lock;
use Cpdeploy\Ui\Format;

/**
 * Manage a site (§9.5). Each entry calls the same service as its command
 * (ARC-03). While another operation holds the site lock, entries that change
 * the site are marked busy (UIG-06); choosing one shows E_LOCKED.
 */
final class ManageSiteMenu
{
    /** Entries that change the site (UIG-06). */
    private const CHANGES_SITE = ['deploy', 'changes', 'rollback', 'php', 'node', 'steps', 'env', 'laravel', 'branch', 'key', 'composer', 'remove'];

    public function __construct(private readonly MenuContext $ctx)
    {
    }

    public function run(string $site): void
    {
        while (true) {
            $config = $this->ctx->services->sites()->load($site);
            $this->header($config);
            $busy = Lock::isHeld($this->ctx->services->paths()->siteLock($site)) ? '  (busy: another operation is running)' : '';
            $options = [
                'deploy' => 'Deploy now',
                'changes' => 'Deploy with changes…',
                'rollback' => 'Roll back…',
                'releases' => 'Releases',
                'php' => 'PHP version',
                'node' => 'Node version',
                'steps' => 'Deploy steps',
                'env' => 'Environment (.env)',
            ];
            if ($config->isLaravel()) {
                $options['laravel'] = 'Laravel tools';
            }
            $options += [
                'branch' => 'Branch',
                'key' => 'Deploy key',
                'composer' => 'Composer credentials',
                'logs' => 'Logs & history',
                'info' => 'Site info',
                'remove' => 'Remove site…',
            ];
            foreach (self::CHANGES_SITE as $key) {
                if (isset($options[$key])) {
                    $options[$key] .= $busy;
                }
            }
            $choice = $this->ctx->choose('Manage ' . $site, $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            try {
                $this->ctx->attempt(fn () => $this->open($site, (string) $choice));
            } catch (SiteRemoved) {
                return;
            }
        }
    }

    private function open(string $site, string $choice): void
    {
        $deploy = new DeployScreen($this->ctx);
        switch ($choice) {
            case 'deploy':
                $deploy->deploy($site);
                break;
            case 'changes':
                $flags = $deploy->withChanges($site);
                if ($flags !== null) {
                    $deploy->deploy($site, $flags);
                }
                break;
            case 'rollback':
                (new ReleasesMenu($this->ctx))->rollback($site, null);
                break;
            case 'releases':
                (new ReleasesMenu($this->ctx))->run($site);
                break;
            case 'php':
                (new PhpMenu($this->ctx))->run($site);
                break;
            case 'node':
                (new NodeMenu($this->ctx))->run($site);
                break;
            case 'steps':
                (new StepsMenu($this->ctx))->run($site);
                break;
            case 'env':
                (new EnvMenu($this->ctx))->run($site);
                break;
            case 'laravel':
                (new LaravelToolsMenu($this->ctx))->run($site);
                break;
            case 'branch':
                $this->branch($site);
                break;
            case 'key':
                (new KeyMenu($this->ctx))->run($site);
                break;
            case 'composer':
                $this->composer($site);
                break;
            case 'logs':
                (new LogsMenu($this->ctx))->site($site);
                break;
            case 'info':
                $this->info($site);
                break;
            case 'remove':
                if ((new RemoveSiteMenu($this->ctx))->run($site)) {
                    throw new SiteRemoved();
                }
                break;
        }
    }

    /**
     * "cpdeploy · shop · shop.example.com · Laravel · PHP 8.2 · Node 20", then the live line.
     */
    private function header(SiteConfig $config): void
    {
        $site = $config->name();
        $this->ctx->title($site, $config->domain(), ucfirst($config->type()), 'PHP ' . $config->phpVersion(), 'Node ' . $config->nodeVersion());
        $live = $this->ctx->services->releases()->live($site);
        $count = count($this->ctx->services->releases()->all($site));
        $this->ctx->line($live === null
            ? 'Not deployed yet'
            : sprintf(
                'Live: %s "%s" · deployed %s · %d release%s',
                $live->short(),
                Format::truncate($live->message(), 36),
                $this->ctx->when($live->get('activated_at') ?? $live->createdAt()),
                $count,
                $count === 1 ? '' : 's',
            ));
    }

    /**
     * §9.5.9: fetch, pick a branch, save, offer to deploy.
     */
    private function branch(string $site): void
    {
        $this->ctx->title($site, 'Branch');
        $mirrors = $this->ctx->services->mirrors();
        $this->ctx->reporter->start('Fetching from GitHub');
        $mirrors->update($site);
        $this->ctx->reporter->succeed('');
        $current = $this->ctx->services->sites()->load($site)->branch();
        $branches = $mirrors->branches($site);
        $options = [];
        foreach ($branches as $branch) {
            $options[$branch] = $branch . ($branch === $current ? '  (current)' : '');
        }
        $choice = $this->ctx->pick('Deploy which branch?', $options);
        if ($choice === MenuContext::BACK || $choice === $current) {
            return;
        }
        $this->ctx->services->siteSettings()->setBranch($site, $choice, $branches);
        $this->ctx->ok("{$site} now deploys {$choice}");
        if ($this->ctx->choose('Deploy now?', ['deploy' => 'Deploy now', 'later' => 'Later'], 'deploy', false) === 'deploy') {
            (new DeployScreen($this->ctx))->deploy($site, new DeployFlags());
        }
    }

    /**
     * §9.5.11 (CMP-06).
     */
    private function composer(string $site): void
    {
        $auth = $this->ctx->services->composerAuth();
        while (true) {
            $this->ctx->title($site, 'Composer credentials');
            $this->ctx->line('Needed for private Composer packages, Laravel Nova and similar: shared/auth.json (600),');
            $this->ctx->line('passed to composer install and never copied into a release.');
            $this->ctx->line($auth->exists($site) ? 'auth.json is set.' : 'No auth.json yet.');
            $options = ['edit' => $auth->exists($site) ? 'Edit auth.json' : 'Create auth.json'];
            if ($auth->exists($site)) {
                $options['remove'] = 'Remove';
            }
            $choice = $this->ctx->choose('Composer credentials', $options);
            if ($choice === MenuContext::BACK) {
                return;
            }
            if ($choice === 'remove') {
                if ($this->ctx->asker->confirm('Remove auth.json?', false)) {
                    $auth->remove($site);
                    $this->ctx->ok('auth.json removed');
                }
                continue;
            }
            $edited = $this->ctx->services->editor()->edit(
                $auth->read($site) ?? ComposerAuth::TEMPLATE,
                'auth-json',
                static fn (string $text): ?string => ComposerAuth::validate($text),
                $this->ctx->asker,
                $this->ctx->reporter,
            );
            if ($edited !== null) {
                $auth->save($site, $edited);
                $this->ctx->ok('auth.json saved');
            }
        }
    }

    /**
     * §9.5.13; disk use only on request (it can be slow).
     */
    private function info(string $site): void
    {
        $sizes = false;
        while (true) {
            $this->ctx->title($site, 'Site info');
            foreach ($this->ctx->services->siteInfo()->facts($site, $sizes) as $section => $facts) {
                $this->ctx->line('');
                $this->ctx->output->writeln(' <options=bold>' . $section . '</>');
                foreach ($facts as $label => $value) {
                    $this->ctx->line(sprintf('  %-18s %s', $label, $value));
                }
            }
            $options = $sizes ? [] : ['sizes' => 'Show disk use'];
            if ($this->ctx->choose('Site info', $options) === MenuContext::BACK) {
                return;
            }
            $sizes = true;
        }
    }
}
