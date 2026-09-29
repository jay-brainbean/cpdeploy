<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Database\DatabaseService;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\GitHub\GitHubRepo;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardStep;

/**
 * Step 1 (§9.3): the repository (from the token's list, or a pasted address),
 * the branch when a token can list them, and the site name. First, the offer
 * to import a site of the old script (§10.6).
 */
final class RepositoryStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $access = $w->services()->repoAccess();
        $w->title(1, 'Repository');

        if (!$w->legacyAsked) {
            $w->legacyAsked = true;
            $legacy = $w->services()->legacyImporter()->find();
            if ($legacy !== []) {
                $options = [];
                foreach ($legacy as $site) {
                    $options[$site->name] = $site->name . ' · ' . ($site->repo?->fullName() ?? 'unknown repo') . ' → ' . ($site->get('DEST') ?? '?');
                }
                $options['new'] = 'No, add a new site';
                $choice = (string) $ctx->asker->select('Import a site set up with cpanel-git-setup.sh?', $options, array_key_first($options));
                foreach ($legacy as $site) {
                    if ($site->name === $choice) {
                        foreach ($w->services()->legacyImporter()->prefill($site, $state, $w->services()->domains()->all()) as $line) {
                            $ctx->line($line);
                        }
                        $ctx->line('Check at step 9 that the deploy steps cover it.');
                        if ($state->repo === null) {
                            $ctx->warn("Couldn't read the repository of {$site->name}: choose it below.");
                        }
                    }
                }
            }
        }

        $before = [$state->repo?->fullName(), $state->branch, $state->name];
        $api = $access->api();
        // Going back keeps the answers (WIZ-02): "<" at the name picks another repository.
        $repo = $state->repo;
        $branch = $state->branch;
        while (true) {
            if ($repo === null) {
                $picked = $this->pick($w, $api);
                if ($picked === null) {
                    return self::CANCEL;
                }
                [$repo, $branch] = $picked;
            }
            $name = $w->text(
                'Site name (used for folders and commands)',
                $state->name !== '' ? $state->name : self::suggest($repo->name),
                'shop',
                fn (string $v): ?string => self::validateName($w, $v),
            );
            if ($name === null) {
                if ($state->legacy !== null) {
                    return self::BACK;
                }
                $repo = null;
                continue;
            }
            break;
        }

        // A new name or repository means the key made for the old ones goes (it
        // was ours), and steps 3–9 are asked again (WIZ-02).
        $repoChanged = $before[0] !== null && $before[0] !== $repo->fullName();
        if ($w->tx->hasKey() && ($repoChanged || $before[2] !== $name)) {
            $instruction = $access->discard($w->tx, $state->repo, $state->name, true);
            $state->keyId = null;
            $state->mirror = null; // discard() also removed the download
            $state->files = null;
            if ($instruction !== null) {
                $ctx->warn($instruction);
            }
        }
        if ($repoChanged || ($before[0] !== null && $branch !== null && $before[1] !== $branch)) {
            $ctx->line('Changed repository — steps 3–9 will be asked again');
            if ($repoChanged) {
                $access->discard($w->tx, null, $state->name, false); // the old download
                $state->mirror = null;
            }
            $state->resetFromType();
            $state->files = null;
        }
        $state->repo = $repo;
        $state->branch = $branch ?? ($before[0] === $repo->fullName() ? $state->branch : null);
        $state->name = $name;

        return self::NEXT;
    }

    /**
     * The repository (and, with a token, the branch). Null = Cancel.
     *
     * @return array{0: RepoUrl, 1: ?string}|null
     */
    private function pick(WizardRun $w, ?GitHubApi $api): ?array
    {
        $ctx = $w->ctx;
        while (true) {
            $choice = $ctx->asker->select('How do you want to pick the repository?', [
                'list' => $api !== null ? 'Choose from my GitHub repos' : 'Choose from my GitHub repos (add a GitHub token in Settings first)',
                'url' => 'Paste a repository URL',
                'cancel' => 'Cancel',
            ], $api !== null ? 'list' : 'url');
            if ($choice === 'cancel') {
                return null;
            }
            if ($choice === 'list') {
                if ($api === null) {
                    $ctx->line('Listing your repositories needs a GitHub token: run cpdeploy token set');
                    $ctx->line('(fine-grained, Administration: Read and write on the repos you deploy). Or paste the URL.');
                    continue;
                }
                $repos = $w->services()->repoAccess()->repos($api);
                $options = [];
                foreach ($repos as $repo) {
                    $options[$repo->fullName] = sprintf('%s · %s%s', $repo->fullName, $repo->private ? 'private' : 'public', $repo->updatedAt !== null ? ' · updated ' . $ctx->when($repo->updatedAt) : '');
                }
                if ($options === []) {
                    $ctx->line('The token sees no repositories.');
                    continue;
                }
                $full = $ctx->pick('Which repository?', $options);
                if ($full === MenuContext::BACK) {
                    continue;
                }
                $repo = RepoUrl::parse($full);
                $info = null;
                foreach ($repos as $r) {
                    if ($r->fullName === $full) {
                        $info = $r;
                    }
                }

                return [$repo, $this->branch($w, $api, $repo, $info)];
            }
            $url = $w->text('Repository', '', 'acme/shop or git@github.com:acme/shop.git', static function (string $v): ?string {
                try {
                    RepoUrl::parse($v);

                    return null;
                } catch (CpdeployException) {
                    return "That isn't a GitHub repository address. Examples: acme/shop or git@github.com:acme/shop.git";
                }
            });
            if ($url === null) {
                continue;
            }

            return [RepoUrl::parse($url), null];
        }
    }

    /**
     * With a token: a select of branches, default = the default branch.
     */
    private function branch(WizardRun $w, GitHubApi $api, RepoUrl $repo, ?GitHubRepo $info): ?string
    {
        try {
            $branches = $api->branches($repo->owner, $repo->name);
        } catch (CpdeployException) {
            return null; // chosen at step 2 instead
        }
        if ($branches === []) {
            return null;
        }
        $default = $info !== null && in_array($info->defaultBranch, $branches, true) ? $info->defaultBranch : $branches[0];

        return (string) $w->ctx->asker->select('Which branch?', array_combine($branches, $branches), $default);
    }

    /**
     * VAL-01: lowercased and sanitised repo name.
     */
    public static function suggest(string $repo): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($repo)), '-');
        $name = substr($name === '' ? 'site' : $name, 0, 31);

        return preg_match('/^[a-z0-9]/', $name) === 1 ? $name : 's' . substr($name, 0, 30);
    }

    public static function validateName(WizardRun $w, string $name): ?string
    {
        if (preg_match(SiteSchema::NAME_PATTERN, $name) !== 1) {
            return 'Lowercase letters, digits and -, up to 31 characters, starting with a letter or digit';
        }
        if (in_array($name, SiteSchema::RESERVED, true)) {
            return "{$name} is a cpdeploy command; choose another name";
        }
        if ($w->services()->sites()->exists($name) || is_dir($w->services()->paths()->siteDir($name))) {
            return "A site named {$name} already exists";
        }
        unset($name);

        return null;
    }

    /**
     * For DatabaseService: the same rule as step 8 shows.
     */
    public static function databaseBase(string $name): string
    {
        return DatabaseService::sanitise($name);
    }
}
