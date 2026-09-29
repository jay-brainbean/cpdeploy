<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

use RuntimeException;

/**
 * A working repository plus a bare "GitHub" remote for tests. Git remotes are
 * reached through CPDEPLOY_GIT_URL_OVERRIDE=file://… (§16.2).
 */
final class GitFixture
{
    public readonly string $remote;

    public function __construct(public readonly string $work, ?string $remote = null)
    {
        $this->remote = $remote ?? $work . '.git';
        if (!is_dir($work)) {
            mkdir($work, 0777, true);
        }
        $this->git('init', '-q', '-b', 'main');
    }

    /**
     * Copies a fixture folder into the working tree.
     */
    public function copyFrom(string $dir): void
    {
        $this->run('cp -a ' . escapeshellarg(rtrim($dir, '/') . '/.') . ' ' . escapeshellarg($this->work));
    }

    public function write(string $path, string $content): void
    {
        $file = $this->work . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    public function delete(string $path): void
    {
        $this->git('rm', '-q', '-r', '--', $path);
    }

    public function commit(string $message): string
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    /**
     * Pushes (or first creates) the bare remote. $force for rewritten history.
     */
    public function push(bool $force = false): void
    {
        if (!is_dir($this->remote)) {
            $this->run('git clone -q --bare ' . escapeshellarg($this->work) . ' ' . escapeshellarg($this->remote));

            return;
        }
        $args = ['push', '-q'];
        if ($force) {
            $args[] = '--force';
        }
        $this->git(...[...$args, $this->remote, '--all']);
        $this->git('push', '-q', '--tags', $this->remote);
    }

    public function git(string ...$args): string
    {
        return $this->run('git -C ' . escapeshellarg($this->work) . ' -c user.name=Dev -c user.email=dev@example.test -c commit.gpgsign=false '
            . implode(' ', array_map('escapeshellarg', $args)));
    }

    private function run(string $cmd): string
    {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException($cmd . "\n" . implode("\n", $out));
        }

        return implode("\n", $out);
    }
}
