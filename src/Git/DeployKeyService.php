<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\GitHub\KeyInUseException;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Ui\Reporter;

/**
 * Per-site deploy keys: generate (GIT-05), register with a token or by hand
 * (GIT-17), test (GIT-06), rotate (GIT-18) and remove (GIT-19). Keys are always
 * read-only on GitHub (SEC-05).
 */
final class DeployKeyService
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Paths $paths,
        private readonly GitRepository $git,
        private readonly Transport $transport,
    ) {
    }

    public function keyPath(string $site): string
    {
        return $this->paths->deployKey($site);
    }

    public function exists(string $site): bool
    {
        return is_file($this->keyPath($site));
    }

    /**
     * GIT-05: ed25519, no passphrase. Never overwrites an existing key file: the
     * caller asks whether to reuse or rotate first.
     */
    public function generate(string $site, string $host, ?string $path = null): string
    {
        $path ??= $this->keyPath($site);
        if (file_exists($path) || file_exists($path . '.pub')) {
            throw new CpdeployException(ErrorCode::USAGE, "A key already exists at {$path}", 'Reuse it, or rotate it (Manage site → Deploy key → Rotate).');
        }
        $ssh = dirname($path);
        if (!is_dir($ssh)) {
            @mkdir($ssh, 0700, true);
        }
        @chmod($ssh, 0700);
        $result = $this->shell->run(
            ['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', "cpdeploy:{$site}@{$host}", '-f', $path],
            new RunOptions(timeout: 30, label: 'ssh-keygen'),
        );
        if (!$result->successful() || !is_file($path) || !is_file($path . '.pub')) {
            throw new CpdeployException(ErrorCode::GIT, "Couldn't create a deploy key: " . trim($result->stderr), 'Check that ssh-keygen works and ~/.ssh is writable.');
        }
        @chmod($path, 0600);
        @chmod($path . '.pub', 0644);

        return $path;
    }

    public function publicKey(string $site, ?string $path = null): string
    {
        $pub = ($path ?? $this->keyPath($site)) . '.pub';
        $key = is_file($pub) ? trim((string) file_get_contents($pub)) : '';
        if ($key === '') {
            throw new CpdeployException(ErrorCode::GIT, "The deploy key {$pub} is missing", 'Rotate the key: Manage site → Deploy key → Rotate.');
        }

        return $key;
    }

    public function fingerprint(string $site, ?string $path = null): ?string
    {
        $result = $this->shell->run(['ssh-keygen', '-l', '-f', ($path ?? $this->keyPath($site)) . '.pub'], new RunOptions(timeout: 15));

        return preg_match('/(SHA256:\S+)/', $result->stdout, $m) === 1 ? $m[1] : null;
    }

    /**
     * GIT-17 title: "cpdeploy · <site> · <user>@<host>".
     */
    public static function title(string $site, string $user, string $host): string
    {
        return "cpdeploy · {$site} · {$user}@{$host}";
    }

    public function instructions(RepoUrl $repo, string $site, string $user, string $host, ?string $path = null): ManualKeyInstructions
    {
        return new ManualKeyInstructions($repo->newKeyUrl(), self::title($site, $user, $host), $this->publicKey($site, $path));
    }

    /**
     * GIT-17 with a token: adds the key read-only and returns its GitHub id. When
     * GitHub says the key is already in use (422), a new key pair is generated and
     * added once more. E_TOKEN_PERMS (403/404) lets the caller fall back to the
     * manual flow.
     */
    public function register(GitHubApi $api, RepoUrl $repo, string $site, string $user, string $host, ?string $path = null, ?string $title = null): int
    {
        $path ??= $this->keyPath($site);
        $title ??= self::title($site, $user, $host);
        try {
            return $api->addKey($repo->owner, $repo->name, $title, $this->publicKey($site, $path));
        } catch (KeyInUseException) {
            @unlink($path);
            @unlink($path . '.pub');
            $this->generate($site, $host, $path);
            try {
                return $api->addKey($repo->owner, $repo->name, $title, $this->publicKey($site, $path));
            } catch (KeyInUseException) {
                throw new CpdeployException(ErrorCode::TOKEN_PERMS, "GitHub keeps refusing a new deploy key for {$repo->fullName()}", 'Add the key manually instead.');
            }
        }
    }

    /**
     * GIT-06: can this key read the repo? Returns the branches, or throws the
     * classified error (E_GIT_AUTH, E_GIT_HOSTKEY, E_GIT_NET, E_GIT).
     *
     * @return array<string, string> branch => sha
     */
    public function test(RepoUrl $repo, string $site, string $transport, ?string $path = null): array
    {
        return $this->git->lsRemote(
            $this->transport->url($repo, $transport),
            SshCommand::build($path ?? $this->keyPath($site), $this->paths->knownHosts()),
            $repo->fullName(),
        );
    }

    /**
     * GIT-18. A new key is generated next to the old one, registered (API, or the
     * user adds it by hand via $addManually), and tested; only then does it replace
     * the old files, and the old GitHub key is deleted. Any failure leaves the old
     * key in place. Returns the new GitHub key id (null when added by hand).
     *
     * @param Closure(ManualKeyInstructions): bool $addManually shows the instructions, returns false to cancel
     */
    public function rotate(
        RepoUrl $repo,
        string $site,
        string $transport,
        string $user,
        string $host,
        ?GitHubApi $api,
        ?int $oldKeyId,
        Closure $addManually,
        string $date,
        ?Reporter $reporter = null,
    ): RotationResult {
        $path = $this->keyPath($site);
        $new = $path . '.new';
        @unlink($new);
        @unlink($new . '.pub');
        $title = self::title($site, $user, $host) . " · {$date}";
        $newId = null;

        try {
            $this->generate($site, $host, $new);
            $reporter?->info('Created a new deploy key');

            if ($api !== null && $api->hasToken()) {
                $newId = $this->register($api, $repo, $site, $user, $host, $new, $title);
                $reporter?->info("Added the new key to {$repo->fullName()}");
            } elseif (!$addManually(new ManualKeyInstructions($repo->newKeyUrl(), $title, $this->publicKey($site, $new)))) {
                throw new CpdeployException(ErrorCode::CANCELLED, 'Cancelled', 'The old deploy key is unchanged.');
            }

            $this->test($repo, $site, $transport, $new);
        } catch (\Throwable $e) {
            if ($newId !== null && $api !== null) {
                try {
                    $api->deleteKey($repo->owner, $repo->name, $newId);
                } catch (CpdeployException) {
                    // Best effort: the new key is useless without its private half.
                }
            }
            @unlink($new);
            @unlink($new . '.pub');
            throw $e;
        }

        if (!@rename($new . '.pub', $path . '.pub') || !@rename($new, $path)) {
            throw new CpdeployException(ErrorCode::GIT, "Couldn't replace {$path} with the new key", 'Check ~/.ssh permissions; the new key is at ' . $new);
        }

        $manualDelete = null;
        if ($oldKeyId !== null && $api !== null && $api->hasToken()) {
            try {
                $api->deleteKey($repo->owner, $repo->name, $oldKeyId);
            } catch (CpdeployException $e) {
                $manualDelete = "Couldn't delete the old key on GitHub ({$e->getMessage()}). Delete it under {$repo->keysUrl()}.";
            }
        } else {
            $manualDelete = sprintf('Delete the old key "%s" under %s', self::title($site, $user, $host), $repo->keysUrl());
        }

        return new RotationResult($newId, $manualDelete);
    }

    /**
     * GIT-19: removes the key from GitHub (404 = already gone) and, if asked, the
     * local files. Returns a manual instruction when there's no token or no id.
     */
    public function remove(RepoUrl $repo, string $site, string $user, string $host, ?GitHubApi $api, ?int $keyId, bool $deleteLocal): ?string
    {
        $instruction = null;
        if ($keyId !== null && $api !== null && $api->hasToken()) {
            $api->deleteKey($repo->owner, $repo->name, $keyId);
        } else {
            $instruction = sprintf('Delete the deploy key "%s" under %s', self::title($site, $user, $host), $repo->keysUrl());
        }
        if ($deleteLocal) {
            @unlink($this->keyPath($site));
            @unlink($this->keyPath($site) . '.pub');
        }

        return $instruction;
    }
}
