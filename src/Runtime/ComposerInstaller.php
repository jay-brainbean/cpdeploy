<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Http;
use Cpdeploy\Support\Lock;
use Cpdeploy\Ui\Reporter;

/**
 * Downloads, verifies and caches composer.phar in ~/cpdeploy/tools/composer (CMP-01).
 * Channels (latest-*) refresh after 30 days; exact versions never do. A copy
 * that fails its SHA-256 check is deleted and never used (SEC-06).
 */
final class ComposerInstaller
{
    public const REFRESH_AFTER = 30 * 86400;

    public function __construct(
        private readonly Http $http,
        private readonly Fs $fs,
        private readonly Paths $paths,
        private readonly string $mirror,
    ) {
    }

    /**
     * composer.version → download channel (CMP-01 table).
     */
    public static function channel(string $version): string
    {
        $version = trim($version);

        return match (true) {
            $version === '2' => 'latest-2.x',
            $version === 'stable' => 'latest-stable',
            $version === '2.2', $version === 'lts' => 'latest-2.2.x',
            preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 => $version,
            default => throw new CpdeployException(
                ErrorCode::CONFIG_INVALID,
                "composer.version '{$version}' isn't valid",
                'Use 2, stable, 2.2 (or lts), or an exact version such as 2.8.4.',
            ),
        };
    }

    public function path(string $version): string
    {
        return $this->paths->composerDir() . '/composer-' . self::channel($version) . '.phar';
    }

    /**
     * Returns the path of a verified composer.phar for $version, downloading it if needed.
     *
     * @param int $exitCode exit code for download/checksum errors (3 in preflight, 4 in a build)
     */
    public function ensure(string $version, ?Reporter $reporter = null, int $exitCode = 3): string
    {
        $channel = self::channel($version);
        $target = $this->path($version);
        if ($this->fresh($target, $channel)) {
            return $target;
        }

        $this->fs->ensureDir($this->paths->toolsDir(), Paths::MODE_ROOT);
        $lock = Lock::blocking($this->paths->toolsLock());
        try {
            // Another process may have downloaded it while we waited for the lock.
            clearstatcache(true, $target);
            if ($this->fresh($target, $channel)) {
                return $target;
            }
            try {
                $this->download($channel, $target, $reporter, $exitCode);
            } catch (CpdeployException $e) {
                if ($e->errorCode === ErrorCode::DOWNLOAD && is_file($target)) {
                    // The cached copy was verified when it was downloaded; keep using it.
                    $reporter?->warn("Couldn't refresh Composer ({$channel}); using the copy downloaded earlier");

                    return $target;
                }
                throw $e;
            }
        } finally {
            $lock->release();
        }

        return $target;
    }

    /**
     * Reads the filesystem, which other processes change.
     *
     * @phpstan-impure
     */
    private function fresh(string $target, string $channel): bool
    {
        if (!is_file($target)) {
            return false;
        }
        if (!str_starts_with($channel, 'latest-')) {
            return true;
        }
        $mtime = @filemtime($target);

        return $mtime !== false && (time() - $mtime) < self::REFRESH_AFTER;
    }

    private function download(string $channel, string $target, ?Reporter $reporter, int $exitCode): void
    {
        $base = rtrim($this->mirror, '/') . '/' . $channel;
        $pharUrl = $base . '/composer.phar';
        $sumUrl = $pharUrl . '.sha256';

        $reporter?->start("Downloading Composer ({$channel})");
        $sum = $this->http->get($sumUrl, timeout: 60);
        if (!$sum->ok() || preg_match('/^\s*([0-9a-f]{64})\b/i', $sum->body, $m) !== 1) {
            $reporter?->fail("Couldn't download the checksum");
            throw $this->downloadError('Composer checksum', $sumUrl, $sum->status, $sum->error, $exitCode);
        }
        $expected = strtolower($m[1]);

        $this->fs->ensureDir($this->paths->composerDir(), Paths::MODE_ROOT);
        $tmp = $this->fs->tempFile('composer');
        try {
            $response = $this->http->download($pharUrl, $tmp, 600);
            if (!$response->ok()) {
                $reporter?->fail("Couldn't download Composer");
                throw $this->downloadError('Composer', $pharUrl, $response->status, $response->error, $exitCode);
            }
            $actual = (string) hash_file('sha256', $tmp);
            if (!hash_equals($expected, $actual)) {
                $reporter?->fail('Checksum mismatch');
                throw new CpdeployException(
                    ErrorCode::CHECKSUM,
                    "composer.phar ({$channel}) failed its checksum — not used",
                    'Try again; if it repeats, check the mirror (Settings → mirrors.composer).',
                    exitCode: $exitCode,
                );
            }
            chmod($tmp, Paths::MODE_PUBLIC_FILE);
            if (!@rename($tmp, $target)) {
                throw new CpdeployException(ErrorCode::DOWNLOAD, "Couldn't save composer.phar to {$target}", 'Check free disk space.', exitCode: $exitCode);
            }
            @touch($target);
            $reporter?->succeed($channel);
        } finally {
            @unlink($tmp);
        }
    }

    private function downloadError(string $what, string $url, int $status, string $error, int $exitCode): CpdeployException
    {
        $why = $status > 0 ? "HTTP {$status}" : ($error !== '' ? $error : 'no response');

        return new CpdeployException(
            ErrorCode::DOWNLOAD,
            "Couldn't download {$what} from {$url} ({$why})",
            'Check the network, or the mirror in Settings (mirrors.composer).',
            exitCode: $exitCode,
        );
    }
}
