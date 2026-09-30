<?php

declare(strict_types=1);

namespace Cpdeploy\Menus;

use Cpdeploy\Deploy\RemoveOptions;
use Cpdeploy\Deploy\RemoveResult;
use Cpdeploy\Docroot\DocrootDetach;

/**
 * *Remove site* (§9.5.14): what happens to the domain, what else goes, and
 * the typed confirmation. `remove` on a terminal without flags uses it too.
 */
final class RemoveSiteMenu
{
    public function __construct(private readonly MenuContext $ctx)
    {
    }

    /**
     * Returns true when the site was removed.
     */
    public function run(string $site): bool
    {
        $services = $this->ctx->services;
        $remover = $services->siteRemover();
        $config = $services->sites()->load($site);
        $this->ctx->title($site, 'Remove site');

        $docroot = null;
        $actions = $remover->docrootActions($config);
        if ($actions === []) {
            $this->ctx->line("{$config->domain()} never went live through cpdeploy: its folder is left as it is.");
        } else {
            $backup = $services->docrootDetach()->backup($config);
            $labels = [
                DocrootDetach::DETACH => 'Keep it running from a plain folder (' . $this->tilde($services->docrootDetach()->appDir($site)) . ')',
                DocrootDetach::RESTORE => 'Put back the folder from before cpdeploy (' . ($backup !== null ? 'backups/' . basename($backup) : '') . ')',
                DocrootDetach::EMPTY => 'Leave an empty folder (the site goes offline)',
            ];
            $choice = $this->ctx->choose("What should happen to {$config->domain()}?", array_intersect_key($labels, array_flip($actions)));
            if ($choice === MenuContext::BACK) {
                return false;
            }
            $docroot = (string) $choice;
        }

        $also = [
            'key' => "Deploy key from GitHub and ~/.ssh/cpdeploy_{$site}",
            'shared' => '.env and uploads for good (otherwise kept in ~/cpdeploy/removed/)',
        ];
        $database = $remover->canDropDatabase($config) ? (string) $config->get('database.name') : null;
        if ($database !== null) {
            $also['db'] = "Database {$database} and its user";
        }
        $chosen = $this->ctx->asker->multiselect('Also remove', $also, ['key'], 'Space to select, Enter to continue');
        $this->ctx->line('The releases and the repository copy are always removed.');

        $typed = $this->ctx->asker->text('Type the site name to confirm', '', $site, false, null, 'Anything else cancels');
        if (trim($typed) !== $site) {
            $this->ctx->line('Not removed.');

            return false;
        }
        $dropDb = false;
        if ($database !== null && in_array('db', $chosen, true)) {
            $dropDb = trim($this->ctx->asker->text("Type the database name ({$database}) to drop it", '', $database, false, null, 'Anything else keeps the database')) === $database;
            if (!$dropDb) {
                $this->ctx->line("The database {$database} is kept.");
            }
        }

        $result = $services->siteRemover()->remove($site, new RemoveOptions(
            $docroot,
            in_array('key', $chosen, true),
            in_array('shared', $chosen, true),
            $dropDb,
        ), $this->ctx->reporter);
        self::summary($this->ctx, $site, $result);
        $this->ctx->pause();

        return true;
    }

    public static function summary(MenuContext $ctx, string $site, RemoveResult $result): void
    {
        $ctx->ok("Site {$site} removed");
        foreach ($result->notes as $note) {
            $ctx->line($note);
        }
        foreach ($result->manual as $todo) {
            $ctx->warn($todo);
        }
    }

    private function tilde(string $path): string
    {
        $home = $this->ctx->services->paths()->home();

        return str_starts_with($path, $home . '/') ? '~' . substr($path, strlen($home)) : $path;
    }
}
