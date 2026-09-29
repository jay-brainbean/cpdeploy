<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Ui\Reporter;

/**
 * npm / pnpm / yarn: detection (NODE-07), availability (NODE-08), commands
 * (NODE-09) and the build environment (NODE-10). Corepack is not used.
 */
final class PackageManager
{
    public function __construct(
        private readonly Shell $shell,
        private readonly Fs $fs,
        private readonly Paths $paths,
    ) {
    }

    /**
     * NODE-07. $exists tells whether a file exists in the project root.
     *
     * @param array<mixed>|null        $packageJson decoded package.json
     * @param Closure(string): bool    $exists
     * @param Closure(string): ?string $read        file contents, for .yarnrc.yml
     */
    public static function detect(?array $packageJson, Closure $exists, Closure $read): PackageManagerChoice
    {
        $declared = is_array($packageJson) && is_string($packageJson['packageManager'] ?? null) ? $packageJson['packageManager'] : null;
        if ($declared !== null && preg_match('/^(npm|pnpm|yarn|bun)@(\d+[^+\s]*)/', $declared, $m) === 1) {
            return self::choose($m[1], $m[2], "packageManager: {$declared}", $exists, $read);
        }
        if ($exists('bun.lock') || $exists('bun.lockb')) {
            return self::choose('bun', null, 'bun lockfile', $exists, $read);
        }
        foreach (['package-lock.json' => 'npm', 'pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn'] as $lock => $pm) {
            if ($exists($lock)) {
                return self::choose($pm, null, $lock, $exists, $read);
            }
        }

        return new PackageManagerChoice(PackageManagerChoice::NPM, null, 'default');
    }

    /**
     * @param Closure(string): bool    $exists
     * @param Closure(string): ?string $read
     */
    private static function choose(string $pm, ?string $version, string $reason, Closure $exists, Closure $read): PackageManagerChoice
    {
        if ($pm === 'bun') {
            throw new CpdeployException(ErrorCode::PM_UNSUPPORTED, "This project uses Bun ({$reason}), which cpdeploy doesn't support", 'Use npm, pnpm or yarn, or build in CI.');
        }
        if ($pm !== 'yarn') {
            return new PackageManagerChoice($pm, $version, $reason);
        }
        $berry = $exists('.yarnrc.yml') || ($version !== null && (int) $version >= 2);
        if (!$berry) {
            return new PackageManagerChoice(PackageManagerChoice::YARN, $version, $reason);
        }
        $rc = $read('.yarnrc.yml') ?? '';
        if (preg_match('/^\s*yarnPath:\s*["\']?([^"\'\s#]+)/m', $rc, $m) === 1) {
            return new PackageManagerChoice(PackageManagerChoice::YARN_BERRY, $version, $reason, $m[1]);
        }
        throw new CpdeployException(
            ErrorCode::PM_UNSUPPORTED,
            "This project uses Yarn 2+ without yarnPath in .yarnrc.yml ({$reason})",
            'Commit the Yarn release (yarn set version stable) so .yarnrc.yml has yarnPath, or use npm.',
        );
    }

    /**
     * NODE-09 install command. For npm without a lockfile the second value is a warning.
     *
     * @param list<string> $pmCommand from command()
     * @return array{0: list<string>, 1: ?string}
     */
    public static function installCommand(PackageManagerChoice $pm, array $pmCommand, bool $hasLockfile): array
    {
        return match ($pm->name) {
            PackageManagerChoice::NPM => $hasLockfile
                ? [[...$pmCommand, 'ci', '--no-audit', '--no-fund'], null]
                : [[...$pmCommand, 'install', '--no-audit', '--no-fund'], 'No package-lock.json: dependencies are resolved fresh (npm install)'],
            PackageManagerChoice::PNPM => [[...$pmCommand, 'install', '--frozen-lockfile'], null],
            PackageManagerChoice::YARN => [[...$pmCommand, 'install', '--frozen-lockfile', '--non-interactive'], null],
            default => [[...$pmCommand, 'install', '--immutable'], null],
        };
    }

    /**
     * @param list<string> $pmCommand
     * @return list<string>
     */
    public static function runCommand(array $pmCommand, string $script): array
    {
        return [...$pmCommand, 'run', $script];
    }

    /**
     * NODE-08: the command that runs the package manager, installing pnpm or
     * yarn v1 into tools/pm when needed. Returns [command prefix, extra PATH dirs].
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function ensure(PackageManagerChoice $pm, NodeVersion $node, ?Reporter $reporter = null, int $exitCode = 4): array
    {
        if ($pm->name === PackageManagerChoice::NPM) {
            return [[$node->binDir . '/npm'], []];
        }
        if ($pm->name === PackageManagerChoice::YARN_BERRY) {
            return [[$node->node(), (string) $pm->yarnPath], []];
        }

        // pnpm, or yarn v1 ("yarn@1" = latest classic).
        $spec = $pm->version ?? ($pm->name === PackageManagerChoice::YARN ? '1' : 'latest');
        $dir = $this->paths->packageManagerDir() . '/' . $pm->name . '-' . $spec;
        $bin = $dir . '/node_modules/.bin';
        if (!is_executable($bin . '/' . $pm->name)) {
            $this->fs->ensureDir($this->paths->toolsDir(), Paths::MODE_ROOT);
            $this->fs->ensureDir($this->paths->packageManagerDir(), Paths::MODE_ROOT);
            $lock = Lock::blocking($this->paths->toolsLock());
            try {
                clearstatcache(true, $bin . '/' . $pm->name);
                if (!is_executable($bin . '/' . $pm->name)) {
                    $reporter?->start("Installing {$pm->name}@{$spec}");
                    $this->fs->ensureDir($dir, Paths::MODE_PUBLIC_DIR);
                    $result = $this->shell->run(
                        [$node->binDir . '/npm', 'install', '--no-audit', '--no-fund', '--prefix', $dir, $pm->name . '@' . $spec],
                        new RunOptions(timeout: 600, pathPrefix: [$node->binDir], label: "npm install {$pm->name}"),
                    );
                    clearstatcache(true, $bin . '/' . $pm->name);
                    if (!$result->successful() || !is_executable($bin . '/' . $pm->name)) {
                        $reporter?->fail("Couldn't install {$pm->name}");
                        throw new CpdeployException(
                            ErrorCode::NODE_INSTALL,
                            "Couldn't install {$pm->name}@{$spec} with npm: " . implode(' ', $result->lastLines(3)),
                            'Check the network access to the npm registry, or use npm.',
                            exitCode: $exitCode,
                        );
                    }
                    $reporter?->succeed();
                }
            } finally {
                $lock->release();
            }
        }

        return [[$bin . '/' . $pm->name], [$bin]];
    }

    /**
     * NODE-10: Node's bin first on PATH, optional heap size. NODE_ENV and CI are
     * deliberately not set.
     *
     * @param list<string> $extraPath
     */
    public static function environment(NodeVersion $node, ?int $maxOldSpaceMb, array $extraPath = []): RunOptions
    {
        $env = [];
        if ($maxOldSpaceMb !== null && $maxOldSpaceMb > 0) {
            $env['NODE_OPTIONS'] = '--max-old-space-size=' . $maxOldSpaceMb;
        }

        return new RunOptions(env: $env, pathPrefix: [$node->binDir, ...$extraPath]);
    }
}
