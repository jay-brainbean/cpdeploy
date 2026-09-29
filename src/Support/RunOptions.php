<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

use Closure;

/**
 * What every Shell run declares (SH-02): working directory, environment additions,
 * timeout and output mode. Immutable; the with*() methods return copies.
 */
final class RunOptions
{
    public const CAPTURE = 'capture';
    public const STREAM = 'stream';
    public const TTY = 'tty';

    /**
     * @param array<string, string> $env         additions to the base environment (SH-03)
     * @param list<string>          $pathPrefix  folders placed before the base PATH (shims, Node bin)
     * @param Closure(string): void|null $onLine called with each output line in stream mode
     * @param Closure(): void|null       $onTick called about every 100 ms while the command runs (spinners)
     */
    public function __construct(
        public readonly ?string $cwd = null,
        public readonly array $env = [],
        public readonly ?float $timeout = 60.0,
        public readonly string $mode = self::CAPTURE,
        public readonly array $pathPrefix = [],
        public readonly ?Closure $onLine = null,
        public readonly ?string $input = null,
        public readonly string $label = '',
        public readonly ?Closure $onTick = null,
    ) {
    }

    public function withCwd(?string $cwd): self
    {
        return new self($cwd, $this->env, $this->timeout, $this->mode, $this->pathPrefix, $this->onLine, $this->input, $this->label, $this->onTick);
    }

    /**
     * @param array<string, string> $env
     */
    public function withEnv(array $env): self
    {
        return new self($this->cwd, $env + $this->env, $this->timeout, $this->mode, $this->pathPrefix, $this->onLine, $this->input, $this->label, $this->onTick);
    }

    public function withTimeout(?float $timeout): self
    {
        return new self($this->cwd, $this->env, $timeout, $this->mode, $this->pathPrefix, $this->onLine, $this->input, $this->label, $this->onTick);
    }

    /**
     * @param list<string> $pathPrefix
     */
    public function withPathPrefix(array $pathPrefix): self
    {
        return new self($this->cwd, $this->env, $this->timeout, $this->mode, $pathPrefix, $this->onLine, $this->input, $this->label, $this->onTick);
    }

    /**
     * @param Closure(string): void $onLine
     */
    public function streaming(Closure $onLine): self
    {
        return new self($this->cwd, $this->env, $this->timeout, self::STREAM, $this->pathPrefix, $onLine, $this->input, $this->label, $this->onTick);
    }

    public function tty(): self
    {
        return new self($this->cwd, $this->env, $this->timeout, self::TTY, $this->pathPrefix, null, null, $this->label, $this->onTick);
    }

    public function withInput(?string $input): self
    {
        return new self($this->cwd, $this->env, $this->timeout, $this->mode, $this->pathPrefix, $this->onLine, $input, $this->label, $this->onTick);
    }

    /**
     * @param Closure(string): void $onLine
     * @param Closure(): void       $onTick
     */
    public function reporting(Closure $onLine, Closure $onTick): self
    {
        return new self($this->cwd, $this->env, $this->timeout, self::STREAM, $this->pathPrefix, $onLine, $this->input, $this->label, $onTick);
    }

    public function withLabel(string $label): self
    {
        return new self($this->cwd, $this->env, $this->timeout, $this->mode, $this->pathPrefix, $this->onLine, $this->input, $label, $this->onTick);
    }
}
