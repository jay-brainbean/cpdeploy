<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Http;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Ui\Reporter;

/**
 * Downloads official Node builds into tools/node (NODE-05) after checking the
 * architecture and glibc (NODE-06).
 */
final class NodeInstaller
{
    /** Official builds of Node 18 and later need glibc 2.28. */
    public const MIN_GLIBC = '2.28';

    public function __construct(
        private readonly Http $http,
        private readonly Fs $fs,
        private readonly Shell $shell,
        private readonly Paths $paths,
        private readonly string $mirror,
        private readonly ?string $machine = null,
        private readonly ?string $glibc = null,
    ) {
    }

    /**
     * x86_64 → x64, aarch64 → arm64; anything else → E_NODE_ARCH.
     */
    public function arch(): string
    {
        $machine = $this->machine ?? php_uname('m');

        return match ($machine) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new CpdeployException(
                ErrorCode::NODE_ARCH,
                "Official Node.js builds aren't available for this server's architecture ({$machine})",
                'Install Node through your host (ea-nodejs or alt-nodejs), or build in CI.',
            ),
        };
    }

    /**
     * glibc version from `getconf GNU_LIBC_VERSION`, falling back to `ldd --version`.
     */
    public function glibcVersion(): ?string
    {
        if ($this->glibc !== null) {
            return $this->glibc;
        }
        $getconf = $this->shell->run(['getconf', 'GNU_LIBC_VERSION'], new RunOptions(timeout: 10));
        if ($getconf->successful() && preg_match('/glibc\s+(\d+\.\d+)/i', $getconf->stdout, $m) === 1) {
            return $m[1];
        }
        $ldd = $this->shell->run(['ldd', '--version'], new RunOptions(timeout: 10));
        if (preg_match('/(\d+\.\d+)\s*$/m', strtok($ldd->output(), "\n") ?: '', $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function targetDir(string $version): string
    {
        return $this->paths->nodeDir() . '/node-v' . $version . '-linux-' . $this->arch();
    }

    /**
     * Downloads, verifies and extracts Node $version; returns it once `node -v` works.
     *
     * @param int $exitCode 3 during preflight, 4 during a build
     */
    public function install(string $version, ?Reporter $reporter = null, int $exitCode = 3): NodeVersion
    {
        $arch = $this->arch();
        $major = (int) explode('.', $version)[0];
        $glibc = $this->glibcVersion();
        if ($major >= 18 && $glibc !== null && version_compare($glibc, self::MIN_GLIBC, '<')) {
            throw new CpdeployException(
                ErrorCode::GLIBC_OLD,
                "Node {$version} needs glibc " . self::MIN_GLIBC . " or newer; this server has {$glibc}",
                'Ask your host for ea-nodejs or alt-nodejs, pick an older Node, or build in CI.',
            );
        }

        $dir = $this->targetDir($version);
        if (is_executable($dir . '/bin/node')) {
            return new NodeVersion($version, $dir . '/bin');
        }

        $this->fs->ensureDir($this->paths->toolsDir(), Paths::MODE_ROOT);
        $this->fs->ensureDir($this->paths->nodeDir(), Paths::MODE_ROOT);
        $lock = Lock::blocking($this->paths->toolsLock());
        try {
            clearstatcache(true, $dir . '/bin/node');
            if (is_executable($dir . '/bin/node')) {
                return new NodeVersion($version, $dir . '/bin');
            }
            $reporter?->start("Downloading Node.js {$version}");
            $this->download($version, $arch, $dir, $exitCode);
            $check = $this->shell->run([$dir . '/bin/node', '-v'], new RunOptions(timeout: 30, label: 'node -v'));
            if (!$check->successful() || trim($check->stdout) !== 'v' . $version) {
                $this->fs->deleteTree($dir);
                $reporter?->fail('node -v failed');
                throw new CpdeployException(
                    ErrorCode::NODE_INSTALL,
                    "The downloaded Node.js {$version} doesn't run on this server: " . trim($check->output()),
                    'Pick another Node version, or ask your host for ea-nodejs / alt-nodejs.',
                    exitCode: $exitCode,
                );
            }
            $reporter?->succeed('v' . $version);
        } finally {
            $lock->release();
        }

        return new NodeVersion($version, $dir . '/bin');
    }

    private function download(string $version, string $arch, string $dir, int $exitCode): void
    {
        $base = rtrim($this->mirror, '/') . '/v' . $version;
        $name = "node-v{$version}-linux-{$arch}.tar.gz";

        $sums = $this->http->get($base . '/SHASUMS256.txt', timeout: 60);
        $expected = null;
        if ($sums->ok() && preg_match('/^([0-9a-f]{64})\s+\*?' . preg_quote($name, '/') . '$/mi', $sums->body, $m) === 1) {
            $expected = strtolower($m[1]);
        }
        if ($expected === null) {
            throw new CpdeployException(
                ErrorCode::DOWNLOAD,
                "Couldn't get the checksum of {$name} from {$base}/SHASUMS256.txt",
                'Check the network, or the mirror in Settings (mirrors.node).',
                exitCode: $exitCode,
            );
        }

        $tarball = $this->fs->tempFile('node-tar');
        $extract = null;
        try {
            $response = $this->http->download($base . '/' . $name, $tarball, 1200);
            if (!$response->ok()) {
                $why = $response->status > 0 ? 'HTTP ' . $response->status : $response->error;
                throw new CpdeployException(ErrorCode::DOWNLOAD, "Couldn't download {$name} from {$base} ({$why})", 'Check the network, or the mirror in Settings (mirrors.node).', exitCode: $exitCode);
            }
            if (!hash_equals($expected, (string) hash_file('sha256', $tarball))) {
                throw new CpdeployException(ErrorCode::CHECKSUM, "{$name} failed its checksum — not used", 'Try again; if it repeats, check the mirror (Settings → mirrors.node).', exitCode: $exitCode);
            }

            // Extract next to the target, then rename into place.
            $extract = $this->paths->toolsDir() . '/cpd-extract-' . bin2hex(random_bytes(6));
            $this->fs->ensureDir($extract, Paths::MODE_PRIVATE_DIR);
            $tar = $this->shell->run(['tar', '-xzf', $tarball, '-C', $extract], new RunOptions(timeout: 600, label: 'tar'));
            $inner = $extract . '/node-v' . $version . '-linux-' . $arch;
            if (!$tar->successful() || !is_file($inner . '/bin/node')) {
                throw new CpdeployException(ErrorCode::NODE_INSTALL, "Couldn't extract {$name}: " . trim($tar->stderr), 'Check free disk space and inodes.', exitCode: $exitCode);
            }
            @chmod($inner, Paths::MODE_PUBLIC_DIR);
            if (!@rename($inner, $dir)) {
                throw new CpdeployException(ErrorCode::NODE_INSTALL, "Couldn't move Node.js into {$dir}", 'Check free disk space.', exitCode: $exitCode);
            }
        } finally {
            @unlink($tarball);
            if ($extract !== null && is_dir($extract)) {
                $this->fs->deleteTree($extract);
            }
        }
    }
}
