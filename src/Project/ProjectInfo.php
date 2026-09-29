<?php

declare(strict_types=1);

namespace Cpdeploy\Project;

/**
 * What a commit contains, as far as deploying it goes (PRJ-01, LAR-02).
 */
final class ProjectInfo
{
    /**
     * @param array<mixed>|null $composerJson
     * @param array<mixed>|null $composerLock
     * @param array<mixed>|null $packageJson
     */
    public function __construct(
        public readonly string $detectedType,
        public readonly ?array $composerJson,
        public readonly ?array $composerLock,
        public readonly ?array $packageJson,
        public readonly ?string $laravelVersion,
        public readonly bool $hasArtisan,
        public readonly bool $usesMix,
    ) {
    }

    public function hasComposer(): bool
    {
        return $this->composerJson !== null;
    }

    public function hasLock(): bool
    {
        return $this->composerLock !== null;
    }

    public function hasScript(string $script): bool
    {
        if ($script === '' || $this->packageJson === null) {
            return false;
        }
        $scripts = $this->packageJson['scripts'] ?? null;

        return is_array($scripts) && is_string($scripts[$script] ?? null) && trim($scripts[$script]) !== '';
    }

    /**
     * "12.31.0" → 12; null when unknown.
     */
    public function laravelMajor(): ?int
    {
        if ($this->laravelVersion === null || preg_match('/^(\d+)/', $this->laravelVersion, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * composer.json "require", strings only.
     *
     * @return array<string, string>
     */
    public function composerRequire(): array
    {
        $require = $this->composerJson['require'] ?? null;
        $out = [];
        foreach (is_array($require) ? $require : [] as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $out[$name] = $constraint;
            }
        }

        return $out;
    }
}
