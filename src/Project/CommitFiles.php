<?php

declare(strict_types=1);

namespace Cpdeploy\Project;

use Closure;
use Cpdeploy\Git\GitRepository;

/**
 * Reads files of one commit in the bare mirror (GIT-10), cached for the run.
 * Everything planned before the export reads the target commit this way (NODE-01).
 */
final class CommitFiles
{
    /** @var array<string, ?string> */
    private array $files = [];

    /** @var array<string, bool> */
    private array $exists = [];

    public function __construct(
        private readonly GitRepository $git,
        private readonly string $mirror,
        public readonly string $sha,
    ) {
    }

    public function read(string $path): ?string
    {
        $path = ltrim($path, '/');
        if (!array_key_exists($path, $this->files)) {
            $this->files[$path] = $this->exists($path) ? $this->git->show($this->mirror, $this->sha, $path) : null;
        }

        return $this->files[$path];
    }

    public function exists(string $path): bool
    {
        $path = ltrim($path, '/');

        return $this->exists[$path] ??= $this->git->exists($this->mirror, $this->sha, $path);
    }

    /**
     * @return array<mixed>|null
     */
    public function json(string $path): ?array
    {
        $raw = $this->read($path);
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return list<string>
     */
    public function list(string $path = '', bool $recursive = false): array
    {
        return $this->git->listFiles($this->mirror, $this->sha, $path, $recursive);
    }

    /**
     * @return Closure(string): ?string
     */
    public function reader(): Closure
    {
        return fn (string $path): ?string => $this->read($path);
    }

    /**
     * @return Closure(string): bool
     */
    public function checker(): Closure
    {
        return fn (string $path): bool => $this->exists($path);
    }
}
