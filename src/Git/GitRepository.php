<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\ProcessResult;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * Git operations: remote checks and the per-site bare mirror (§7.4).
 * Network calls get GIT_SSH_COMMAND (GIT-02); local calls don't need it.
 */
final class GitRepository
{
    /** Separator for `git log --format` fields (GIT-11). */
    private const SEP = "\x1f";

    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly float $timeout = 300.0,
    ) {
    }

    /**
     * GIT-06: `git ls-remote --heads`. Returns branch => sha, or throws the
     * classified error.
     *
     * @return array<string, string>
     */
    public function lsRemote(string $url, string $sshCommand, string $repoName): array
    {
        $result = $this->network(['git', 'ls-remote', '--heads', $url], $sshCommand, 60.0);
        if (!$result->successful()) {
            throw self::classify($result, $repoName);
        }
        $branches = [];
        foreach (preg_split('/\r?\n/', trim($result->stdout)) ?: [] as $line) {
            if (preg_match('#^([0-9a-f]{40,64})\s+refs/heads/(.+)$#', $line, $m) === 1) {
                $branches[$m[2]] = $m[1];
            }
        }

        return $branches;
    }

    /**
     * GIT-15: the remote's default branch, from `ls-remote --symref <url> HEAD`.
     */
    public function defaultBranch(string $url, string $sshCommand, string $repoName): ?string
    {
        $result = $this->network(['git', 'ls-remote', '--symref', $url, 'HEAD'], $sshCommand, 60.0);
        if (!$result->successful()) {
            throw self::classify($result, $repoName);
        }

        return preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD$#m', $result->stdout, $m) === 1 ? $m[1] : null;
    }

    /**
     * GIT-07: bare mirror whose refs/heads track the remote's branches.
     */
    public function cloneMirror(string $url, string $dir, string $sshCommand, string $repoName): void
    {
        $result = $this->network(['git', 'clone', '--bare', '--quiet', $url, $dir], $sshCommand, $this->timeout);
        if (!$result->successful()) {
            throw self::classify($result, $repoName);
        }
        @chmod($dir, 0700);
        $this->local($dir, ['config', 'remote.origin.fetch', '+refs/heads/*:refs/heads/*']);
        $this->local($dir, ['config', 'core.logAllRefUpdates', 'false']);
    }

    /**
     * GIT-08: fetch branches and tags, pruning deleted branches.
     */
    public function fetch(string $dir, string $sshCommand, string $repoName): void
    {
        $result = $this->network(['git', '-C', $dir, 'fetch', '--prune', '--tags', '--quiet', 'origin'], $sshCommand, $this->timeout);
        if (!$result->successful()) {
            throw self::classify($result, $repoName);
        }
    }

    /**
     * GIT-16: output that means the mirror itself is damaged.
     */
    public static function isCorruption(string $output): bool
    {
        foreach ([
            'bad object', 'corrupt', 'does not appear to be a git repository',
            // Seen with a damaged pack file (real git 2.43 output):
            'inflate: data stream error', 'failed to read delta', 'unable to read', 'packfile',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * GIT-16 repair: move the broken mirror aside, clone afresh, then delete the
     * broken copy; on failure put it back.
     */
    public function reclone(string $url, string $dir, string $sshCommand, string $repoName, string $stamp): void
    {
        $broken = $dir . '.broken-' . $stamp;
        if (!@rename($dir, $broken)) {
            throw new CpdeployException(ErrorCode::GIT, "Couldn't move the damaged mirror {$dir} aside", 'Check the folder permissions.');
        }
        try {
            $this->cloneMirror($url, $dir, $sshCommand, $repoName);
        } catch (CpdeployException $e) {
            if (is_dir($dir)) {
                $this->fs->deleteTree($dir);
            }
            @rename($broken, $dir);
            throw $e;
        }
        $this->fs->deleteTree($broken);
    }

    /**
     * GIT-09: exact branch → tag → commit SHA (7–40 hex).
     * $configuredBranch: the site's branch; when $ref is that branch and it's gone → E_BRANCH_GONE.
     */
    public function resolve(string $dir, string $ref, string $repoName, ?string $configuredBranch = null): string
    {
        foreach (["refs/heads/{$ref}^{commit}", "refs/tags/{$ref}^{commit}"] as $candidate) {
            $sha = $this->revParse($dir, $candidate);
            if ($sha !== null) {
                return $sha;
            }
        }
        if (preg_match('/^[0-9a-f]{7,40}$/i', $ref) === 1) {
            $sha = $this->revParse($dir, $ref . '^{commit}');
            if ($sha !== null) {
                return $sha;
            }
        }
        if ($configuredBranch !== null && $ref === $configuredBranch) {
            throw new CpdeployException(ErrorCode::BRANCH_GONE, "Branch {$ref} no longer exists on GitHub", 'Choose another branch: Manage site → Branch.');
        }
        throw new CpdeployException(ErrorCode::REF_NOT_FOUND, "{$ref} isn't a branch, tag or commit in {$repoName}", 'Check the name; the list comes from GitHub.');
    }

    /**
     * @return list<string>
     */
    public function branches(string $dir): array
    {
        $out = $this->local($dir, ['for-each-ref', '--format=%(refname:short)', 'refs/heads']);

        return array_values(array_filter(explode("\n", trim($out)), static fn (string $b): bool => $b !== ''));
    }

    /**
     * GIT-10: a file at a commit, or null when it doesn't exist there.
     */
    public function show(string $dir, string $sha, string $path): ?string
    {
        $result = $this->shell->run(['git', '-C', $dir, 'show', $sha . ':' . ltrim($path, '/')], new RunOptions(timeout: 60, label: 'git show'));

        return $result->successful() ? $result->stdout : null;
    }

    /**
     * Whether a file or folder exists at a commit (`git cat-file -e`).
     */
    public function exists(string $dir, string $sha, string $path): bool
    {
        $result = $this->shell->run(['git', '-C', $dir, 'cat-file', '-e', $sha . ':' . ltrim($path, '/')], new RunOptions(timeout: 30, label: 'git cat-file'));

        return $result->successful();
    }

    /**
     * Paths at a commit under $path ("" = the root), relative to the repository root.
     * Not recursive unless $recursive.
     *
     * @return list<string>
     */
    public function listFiles(string $dir, string $sha, string $path = '', bool $recursive = false): array
    {
        $args = ['ls-tree', '--name-only'];
        if ($recursive) {
            $args[] = '-r';
        }
        $args[] = $sha;
        $path = trim($path, '/');
        if ($path !== '') {
            $args[] = '--';
            $args[] = $path . '/';
        }
        $out = $this->local($dir, $args);

        return array_values(array_filter(explode("\n", $out), static fn (string $l): bool => $l !== ''));
    }

    /**
     * `git diff --name-status --no-renames`: a rename is a delete plus an add.
     *
     * @return list<array{0: string, 1: string}> [A|M|D|T, path]
     */
    public function changedFiles(string $dir, string $from, string $to, string ...$paths): array
    {
        $args = ['diff', '--name-status', '--no-renames', $from, $to];
        if ($paths !== []) {
            $args[] = '--';
            array_push($args, ...array_values($paths));
        }
        $out = $this->local($dir, $args);
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (preg_match('/^([A-Z])\d*\t(.+)$/', $line, $m) === 1) {
                $rows[] = [$m[1], $m[2]];
            }
        }

        return $rows;
    }

    /**
     * Number of commits in a range, e.g. "a..b" (`git rev-list --count`).
     */
    public function count(string $dir, string $range): int
    {
        return (int) trim($this->local($dir, ['rev-list', '--count', $range]));
    }

    /**
     * GIT-11: one commit's details.
     */
    public function commit(string $dir, string $sha): Commit
    {
        $out = $this->local($dir, ['log', '-1', '--format=' . self::format(), $sha]);
        $commit = self::parseCommit(trim($out));
        if ($commit === null) {
            throw new CpdeployException(ErrorCode::GIT, "Git failed: couldn't read commit {$sha}", 'See the log.');
        }

        return $commit;
    }

    /**
     * GIT-11: up to $limit commits in from..to (newest first). $from null = history of $to.
     *
     * @return list<Commit>
     */
    public function log(string $dir, ?string $from, string $to, int $limit = 50): array
    {
        $range = $from === null ? $to : $from . '..' . $to;
        $out = $this->local($dir, ['log', '--format=' . self::format(), $range, '-n', (string) $limit]);
        $commits = [];
        foreach (explode("\n", trim($out)) as $line) {
            $commit = self::parseCommit($line);
            if ($commit !== null) {
                $commits[] = $commit;
            }
        }

        return $commits;
    }

    /**
     * GIT-12: whether $ancestor is an ancestor of $descendant (not a rewind).
     */
    public function isAncestor(string $dir, string $ancestor, string $descendant): bool
    {
        $result = $this->shell->run(['git', '-C', $dir, 'merge-base', '--is-ancestor', $ancestor, $descendant], new RunOptions(timeout: 60));
        if ($result->exitCode === 0) {
            return true;
        }
        if ($result->exitCode === 1) {
            return false;
        }
        throw new CpdeployException(ErrorCode::GIT, 'Git failed: ' . trim($result->stderr), 'See the log.');
    }

    /**
     * @return list<array{0: string, 1: string}> [status letter, path] from `git diff --name-status`
     */
    public function diffNames(string $dir, string $from, string $to, string ...$paths): array
    {
        $out = $this->local($dir, ['diff', '--name-status', $from, $to, '--', ...array_values($paths)]);
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (preg_match('/^([A-Z])\d*\t(?:[^\t]+\t)?(.+)$/', $line, $m) === 1) {
                $rows[] = [$m[1], $m[2]];
            }
        }

        return $rows;
    }

    /**
     * GIT-13: export a commit into a folder (`git archive | tar -x`), then check
     * that at least one file arrived. export-ignore paths are not exported.
     */
    public function export(string $dir, string $sha, string $target): void
    {
        $script = sprintf(
            'git -C %s archive --format=tar %s | tar -xf - -C %s',
            Shell::quote($dir),
            Shell::quote($sha),
            Shell::quote($target),
        );
        $result = $this->shell->pipeline($script, new RunOptions(timeout: $this->timeout, label: 'export'));
        $entries = is_dir($target) ? array_diff(scandir($target) ?: [], ['.', '..']) : [];
        if (!$result->successful() || $entries === []) {
            throw $this->shell->failure(
                $result,
                new RunOptions(timeout: $this->timeout, label: 'export'),
                ErrorCode::EXPORT,
                "Couldn't extract commit " . substr($sha, 0, 7) . ($result->successful() ? ' (it has no files)' : ': ' . trim($result->stderr)),
                'Disk space? See the log.',
            );
        }
    }

    /**
     * GIT-14: submodules and Git LFS aren't supported.
     */
    public function assertSupported(string $dir, string $sha): void
    {
        if ($this->show($dir, $sha, '.gitmodules') !== null) {
            throw new CpdeployException(ErrorCode::SUBMODULES, "This repo uses git submodules, which cpdeploy doesn't support yet", 'Vendor the code instead, or deploy with another tool.');
        }
        $attributes = $this->show($dir, $sha, '.gitattributes');
        if ($attributes !== null && str_contains($attributes, 'filter=lfs')) {
            throw new CpdeployException(ErrorCode::LFS, "This repo uses Git LFS, which cpdeploy doesn't support yet", 'Keep large files outside git, or deploy with another tool.');
        }
    }

    /**
     * GIT-06 error classes from stderr.
     */
    public static function classify(ProcessResult $result, string $repoName): CpdeployException
    {
        $err = $result->stderr . "\n" . $result->stdout;
        if ($result->timedOut) {
            return new CpdeployException(ErrorCode::GIT_NET, "Can't reach GitHub on port 22 or 443 (no answer)", 'Ask your host to allow outbound SSH to github.com.');
        }
        if (str_contains($err, 'Host key verification failed')) {
            return new CpdeployException(ErrorCode::GIT_HOSTKEY, "GitHub's SSH host key doesn't match the known key", 'Run: cpdeploy self-update, or cpdeploy check --refresh-host-keys');
        }
        foreach (['Permission denied (publickey)', 'Repository not found', 'Could not read from remote repository'] as $needle) {
            if (str_contains($err, $needle)) {
                return new CpdeployException(
                    ErrorCode::GIT_AUTH,
                    "GitHub refused the deploy key for {$repoName}",
                    "Manage site → Deploy key → Test / Rotate; check the key under the repo's Settings → Deploy keys.",
                );
            }
        }
        foreach (['Connection timed out', 'Connection refused', 'Could not resolve hostname', 'Network is unreachable', 'Connection closed by', 'No route to host'] as $needle) {
            if (str_contains($err, $needle)) {
                return new CpdeployException(ErrorCode::GIT_NET, "Can't reach GitHub on port 22 or 443", 'Ask your host to allow outbound SSH to github.com.');
            }
        }
        $lines = $result->lastLines(3);

        return new CpdeployException(ErrorCode::GIT, 'Git failed: ' . ($lines !== [] ? implode(' ', $lines) : 'exit code ' . $result->exitCode), 'See the log.');
    }

    private static function format(): string
    {
        return implode('%x1f', ['%H', '%h', '%an', '%ae', '%at', '%s']);
    }

    private static function parseCommit(string $line): ?Commit
    {
        $parts = explode(self::SEP, $line);
        if (count($parts) !== 6 || preg_match('/^[0-9a-f]{40,64}$/', $parts[0]) !== 1) {
            return null;
        }

        return new Commit($parts[0], $parts[1], $parts[2], $parts[3], (int) $parts[4], $parts[5]);
    }

    private function revParse(string $dir, string $spec): ?string
    {
        $result = $this->shell->run(['git', '-C', $dir, 'rev-parse', '--verify', '--quiet', $spec], new RunOptions(timeout: 30));
        $sha = trim($result->stdout);

        return $result->successful() && preg_match('/^[0-9a-f]{40,64}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * @param list<string> $args
     */
    private function local(string $dir, array $args): string
    {
        $result = $this->shell->run(['git', '-C', $dir, ...$args], new RunOptions(timeout: 120, label: 'git ' . $args[0]));
        if (!$result->successful()) {
            throw new CpdeployException(ErrorCode::GIT, 'Git failed: ' . implode(' ', $result->lastLines(3)), 'See the log.');
        }

        return $result->stdout;
    }

    /**
     * @param list<string> $argv
     */
    private function network(array $argv, string $sshCommand, float $timeout): ProcessResult
    {
        return $this->shell->run($argv, new RunOptions(env: ['GIT_SSH_COMMAND' => $sshCommand], timeout: $timeout, label: 'git ' . $argv[1]));
    }
}
