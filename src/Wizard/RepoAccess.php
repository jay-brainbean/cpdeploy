<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Git\DeployKeyService;
use Cpdeploy\Git\GitRepository;
use Cpdeploy\Git\ManualKeyInstructions;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Git\SshCommand;
use Cpdeploy\Git\Transport;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\GitHub\GitHubRepo;
use Cpdeploy\Project\CommitFiles;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\SystemInfo;
use RuntimeException;

/**
 * Step 1–2 of the wizard and `add --from` (§9.3, §7.4): the GitHub repo list,
 * the deploy key (created, registered with a token or shown for adding by
 * hand), the access test, the temporary bare clone (WIZ-01), and cleanup on
 * cancel (WIZ-03).
 */
final class RepoAccess
{
    /**
     * @param Closure(): GitHubApi $api the API with the stored token (if any)
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly SystemInfo $system,
        private readonly DeployKeyService $keys,
        private readonly GitRepository $git,
        private readonly Transport $transport,
        private readonly Closure $api,
    ) {
    }

    /**
     * The token API, or null without a (usable) token.
     */
    public function api(): ?GitHubApi
    {
        $api = ($this->api)();

        return $api->hasToken() ? $api : null;
    }

    /**
     * @return list<GitHubRepo>
     */
    public function repos(GitHubApi $api): array
    {
        return $api->repos();
    }

    /**
     * GIT-04: port 22, else 443.
     */
    public function detectTransport(): string
    {
        return $this->transport->detect();
    }

    /**
     * GIT-05 / GIT-17: creates ~/.ssh/cpdeploy_<site> unless it exists (a re-run
     * of `add --from` reuses it), then registers it read-only with the token.
     * Returns the instructions to add it by hand when there is no token (or the
     * token may not add keys to this repo), else null.
     */
    public function prepareKey(RepoUrl $repo, string $site, WizardState $state, WizardTransaction $tx, ?GitHubApi $api): ?ManualKeyInstructions
    {
        $user = $this->system->userName();
        $host = $this->system->hostName();
        if (!$this->keys->exists($site)) {
            $this->keys->generate($site, $host);
            $tx->keyCreated = true;
        }
        if ($api !== null && $state->keyId === null) {
            try {
                $state->keyId = $this->keys->register($api, $repo, $site, $user, $host);
                $tx->keyId = $state->keyId;

                return null;
            } catch (CpdeployException) {
                // E_TOKEN_PERMS and similar: fall back to adding the key by hand (GIT-17).
            }
        }

        return $state->keyId !== null ? null : $this->keys->instructions($repo, $site, $user, $host);
    }

    /**
     * GIT-06 with the site's key. Returns the branches, or throws the classified error.
     *
     * @return list<string>
     */
    public function test(RepoUrl $repo, string $site, string $transport): array
    {
        return array_keys($this->keys->test($repo, $site, $transport));
    }

    /**
     * GIT-15: the repository's default branch.
     */
    public function defaultBranch(RepoUrl $repo, string $site, string $transport): ?string
    {
        return $this->git->defaultBranch($this->transport->url($repo, $transport), $this->ssh($site), $repo->fullName());
    }

    /**
     * WIZ-01: a bare clone in tmp/cpd-wizard-<rand>/repo.git.
     */
    public function cloneTemporary(RepoUrl $repo, string $site, string $transport, WizardTransaction $tx): string
    {
        $dir = $this->fs->tempDir('wizard');
        $tx->tmpDir = $dir;
        $mirror = $dir . '/repo.git';
        $this->git->cloneMirror($this->transport->url($repo, $transport), $mirror, $this->ssh($site), $repo->fullName());

        return $mirror;
    }

    /**
     * The files at the branch head, refusing submodules and LFS (GIT-14).
     */
    public function files(string $mirror, RepoUrl $repo, string $branch): CommitFiles
    {
        $sha = $this->git->resolve($mirror, $branch, $repo->fullName(), $branch);
        $this->git->assertSupported($mirror, $sha);

        return new CommitFiles($this->git, $mirror, $sha);
    }

    /**
     * WIZ-03: the temporary clone always goes; the key (locally and on GitHub)
     * only when $removeKey. Returns an instruction when the GitHub key has to be
     * deleted by hand.
     */
    public function discard(WizardTransaction $tx, ?RepoUrl $repo, string $site, bool $removeKey): ?string
    {
        if ($tx->tmpDir !== null && is_dir($tx->tmpDir)) {
            try {
                $this->fs->deleteTree($tx->tmpDir);
            } catch (RuntimeException) {
                // tmp/ is cleaned after 24 h anyway (LAY-03).
            }
            $tx->tmpDir = null;
        }
        if (!$removeKey || !$tx->hasKey()) {
            return null;
        }
        $instruction = null;
        if ($repo !== null && $tx->keyId !== null) {
            try {
                $instruction = $this->keys->remove($repo, $site, $this->system->userName(), $this->system->hostName(), $this->api(), $tx->keyId, false);
            } catch (CpdeployException $e) {
                $instruction = "Couldn't delete the key on GitHub ({$e->getMessage()}): delete it under {$repo->keysUrl()}";
            }
            $tx->keyId = null;
        }
        if ($tx->keyCreated) {
            @unlink($this->keys->keyPath($site));
            @unlink($this->keys->keyPath($site) . '.pub');
            $tx->keyCreated = false;
        }

        return $instruction;
    }

    /**
     * LEG-03: the old script's key, copied (never moved) to ~/.ssh/cpdeploy_<site>.
     */
    public function copyLegacyKey(string $from, string $site, WizardTransaction $tx): void
    {
        $to = $this->keys->keyPath($site);
        if (is_file($to)) {
            return;
        }
        $this->fs->ensureDir($this->paths->sshDir(), Paths::MODE_PRIVATE_DIR);
        $this->fs->writeAtomic($to, (string) file_get_contents($from), Paths::MODE_SECRET_FILE);
        if (is_file($from . '.pub')) {
            $this->fs->writeAtomic($to . '.pub', (string) file_get_contents($from . '.pub'), Paths::MODE_PUBLIC_FILE);
        }
        $tx->keyCreated = true;
    }

    public function keyPath(string $site): string
    {
        return $this->keys->keyPath($site);
    }

    public function publicKey(string $site): string
    {
        return trim($this->keys->publicKey($site));
    }

    private function ssh(string $site): string
    {
        return SshCommand::build($this->keys->keyPath($site), $this->paths->knownHosts());
    }
}
