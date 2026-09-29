<?php

declare(strict_types=1);

namespace Cpdeploy\Update;

use Closure;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Cpdeploy\Config\Paths;
use Cpdeploy\GitHub\GitHubApi;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Ui\Reporter;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * `cpdeploy self-update` (§15.5): the newest release of update.repo (UPD-01),
 * downloaded, checked against its .sha256 and run once before it replaces
 * ~/cpdeploy/app/cpdeploy.phar, keeping the old one as .prev (UPD-02);
 * --check only reports and --rollback swaps .prev back (UPD-03).
 */
final class SelfUpdate
{
    public const PHAR = 'cpdeploy.phar';
    public const SHA = 'cpdeploy.phar.sha256';

    /**
     * @param Closure(): GitHubApi $api
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Shell $shell,
        private readonly Closure $api,
        private readonly string $repo,
        private readonly string $current,
        private readonly string $phpBinary = PHP_BINARY,
    ) {
    }

    public function current(): string
    {
        return $this->current;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    /**
     * The newest published release (pre-releases only with $pre), or null.
     */
    public function latest(bool $pre = false): ?UpdateRelease
    {
        [$owner, $name] = explode('/', $this->repo, 2) + [1 => ''];
        $best = null;
        foreach (($this->api)()->releases($owner, $name) as $row) {
            if (($row['draft'] ?? false) === true || (($row['prerelease'] ?? false) === true && !$pre)) {
                continue;
            }
            $release = UpdateRelease::fromApi($row);
            if ($release === null || !self::valid($release->version)) {
                continue;
            }
            if ($best === null || Comparator::greaterThan($release->version, $best->version)) {
                $best = $release;
            }
        }

        return $best;
    }

    /**
     * True when $latest is newer than the running version (a development build
     * counts as older than any release).
     */
    public function isNewer(UpdateRelease $latest): bool
    {
        if (!self::valid($this->current)) {
            return true;
        }

        return Comparator::greaterThan($latest->version, $this->current);
    }

    /**
     * UPD-02: download, verify, try, then swap in (the old phar becomes .prev).
     */
    public function update(UpdateRelease $release, Reporter $reporter): void
    {
        $installed = $this->paths->phar();
        if (!is_file($installed)) {
            throw new CpdeployException(ErrorCode::UPDATE, "Update failed: cpdeploy isn't installed at {$installed}", 'The current version is unchanged. Install it with install.sh (see the README).');
        }
        if ($release->pharUrl === null || $release->shaUrl === null) {
            throw new CpdeployException(ErrorCode::UPDATE, "Update failed: release {$release->tag} has no " . self::PHAR . ' or ' . self::SHA, 'The current version is unchanged.');
        }
        $tmp = $this->fs->tempDir('update');
        try {
            $reporter->start("Downloading cpdeploy {$release->version}");
            $api = ($this->api)();
            $api->downloadAsset($release->pharUrl, $tmp . '/' . self::PHAR);
            $api->downloadAsset($release->shaUrl, $tmp . '/' . self::SHA);
            $expected = strtolower((string) strtok(trim((string) file_get_contents($tmp . '/' . self::SHA)), " \t"));
            $actual = hash_file('sha256', $tmp . '/' . self::PHAR);
            if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1 || $expected !== $actual) {
                throw new CpdeployException(ErrorCode::UPDATE, 'Update failed: the downloaded phar does not match its SHA-256', 'The current version is unchanged. Try again later.');
            }
            $check = $this->shell->run([$this->phpBinary, $tmp . '/' . self::PHAR, '--version'], new RunOptions(timeout: 60, label: 'new cpdeploy --version'));
            if (!$check->successful() || !str_contains($check->stdout, $release->version)) {
                throw new CpdeployException(ErrorCode::UPDATE, 'Update failed: the new version does not start (' . trim($check->output()) . ')', 'The current version is unchanged.');
            }
            $reporter->succeed($release->tag);

            // Same folder, so the rename is atomic; running processes keep the old file.
            $next = dirname($installed) . '/.' . self::PHAR . '.new';
            if (!@copy($tmp . '/' . self::PHAR, $next)) {
                throw new CpdeployException(ErrorCode::UPDATE, "Update failed: can't write {$next}", 'The current version is unchanged.');
            }
            @chmod($next, 0755);
            if (!@copy($installed, $this->paths->previousPhar())) {
                @unlink($next);
                throw new CpdeployException(ErrorCode::UPDATE, "Update failed: can't keep the old version as {$this->paths->previousPhar()}", 'The current version is unchanged.');
            }
            @chmod($this->paths->previousPhar(), 0755);
            if (!@rename($next, $installed)) {
                @unlink($next);
                throw new CpdeployException(ErrorCode::UPDATE, "Update failed: can't replace {$installed}", 'The current version is unchanged.');
            }
        } catch (CpdeployException $e) {
            if ($e->errorCode !== ErrorCode::UPDATE) {
                throw new CpdeployException(ErrorCode::UPDATE, 'Update failed: ' . $e->getMessage(), 'The current version is unchanged.', previous: $e);
            }
            throw $e;
        } finally {
            try {
                $this->fs->deleteTree($tmp);
            } catch (RuntimeException) {
                // tmp/ is cleaned after 24 h anyway.
            }
        }
    }

    /**
     * UPD-03: the previous phar comes back (and the current one becomes .prev).
     * Returns the version now installed, as it reports itself.
     */
    public function rollback(): string
    {
        $installed = $this->paths->phar();
        $previous = $this->paths->previousPhar();
        if (!is_file($previous)) {
            throw new CpdeployException(ErrorCode::UPDATE, 'Rollback failed: there is no previous version (' . $previous . ')', 'The current version is unchanged.');
        }
        $swap = dirname($installed) . '/.' . self::PHAR . '.swap';
        $fail = static fn (): CpdeployException => new CpdeployException(ErrorCode::UPDATE, "Rollback failed: couldn't swap {$previous} and {$installed}", 'Check the files in ~/cpdeploy/app.');
        if (!@rename($previous, $swap)) {
            throw $fail();
        }
        if (is_file($installed) && !@rename($installed, $previous)) {
            @rename($swap, $previous);
            throw $fail();
        }
        if (!@rename($swap, $installed)) {
            @rename($previous, $installed);
            @rename($swap, $previous);
            throw $fail();
        }
        try {
            $result = $this->shell->run([$this->phpBinary, $installed, '--version'], new RunOptions(timeout: 60, label: 'cpdeploy --version'));

            return trim($result->stdout);
        } catch (Throwable) {
            return '';
        }
    }

    private static function valid(string $version): bool
    {
        try {
            (new VersionParser())->normalize($version);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
