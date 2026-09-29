<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use UnexpectedValueException;

/**
 * A Node version request (NODE-02): exact, major, major.minor, a semver range,
 * lts/*, lts/<codename>, or node/latest/current. A leading "v" is ignored.
 */
final class NodeSpec
{
    public const CONSTRAINT = 'constraint';
    public const LTS = 'lts';
    public const LATEST = 'latest';

    private function __construct(
        public readonly string $raw,
        public readonly string $source,
        public readonly string $kind,
        public readonly ?string $constraint = null,
        public readonly ?string $ltsName = null,
    ) {
    }

    /**
     * @param string $source where the value came from, for error messages (e.g. ".nvmrc")
     */
    public static function parse(string $raw, string $source): self
    {
        $value = trim($raw);
        $lower = strtolower($value);

        if (in_array($lower, ['node', 'latest', 'current', 'stable'], true)) {
            return new self($raw, $source, self::LATEST);
        }
        if (preg_match('#^lts/(\*|[a-z]+)$#', $lower, $m) === 1) {
            return new self($raw, $source, self::LTS, null, $m[1] === '*' ? null : $m[1]);
        }

        $constraint = self::normalise($value);
        if ($constraint !== null) {
            try {
                (new VersionParser())->parseConstraints($constraint);

                return new self($raw, $source, self::CONSTRAINT, $constraint);
            } catch (UnexpectedValueException) {
                // Fall through to the error below.
            }
        }

        throw new CpdeployException(
            ErrorCode::NODE_SPEC,
            sprintf('"%s" in %s is not a Node version this tool understands', $value, $source),
            'Use a version such as 22, 22.20.0, ^20, lts/* or lts/jod.',
        );
    }

    public function matches(string $version): bool
    {
        $version = ltrim($version, 'v');

        return match ($this->kind) {
            self::LATEST => true,
            self::CONSTRAINT => $this->constraint !== null && Semver::satisfies($version, $this->constraint),
            // LTS needs the release index to know which versions are LTS.
            default => false,
        };
    }

    public function describe(): string
    {
        return sprintf('%s (from %s)', trim($this->raw), $this->source);
    }

    private static function normalise(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        // A leading "v" on each version in the expression: v20, >=v18 <v21.
        $value = (string) preg_replace('/(^|[\s,|^~<>=])v(?=\d)/i', '$1', $value);
        if (preg_match('/^\d+$/', $value) === 1) {
            return $value . '.*';
        }
        if (preg_match('/^\d+\.\d+$/', $value) === 1) {
            return $value . '.*';
        }

        return $value;
    }
}
