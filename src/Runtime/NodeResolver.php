<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Http;

/**
 * Turns a Node version request into a concrete version (NODE-01, NODE-02, NODE-04).
 */
final class NodeResolver
{
    public const INDEX_MAX_AGE = 86400;

    /** @var list<array{version: string, lts: string|false, files: list<string>}>|null */
    private ?array $index = null;

    public function __construct(
        private readonly NodeLocator $locator,
        private readonly Http $http,
        private readonly Fs $fs,
        private readonly Paths $paths,
        private readonly string $mirror,
    ) {
    }

    /**
     * NODE-01: the first source that has a value wins. $read returns a file of the
     * target commit, or null when it doesn't exist.
     *
     * @param Closure(string): ?string $read
     */
    public static function specFromProject(?string $siteVersion, Closure $read): ?NodeSpec
    {
        if ($siteVersion !== null && trim($siteVersion) !== '' && strtolower(trim($siteVersion)) !== 'auto') {
            return NodeSpec::parse($siteVersion, 'site.yml (node.version)');
        }
        foreach (['.nvmrc', '.node-version'] as $file) {
            $content = $read($file);
            if ($content !== null) {
                // First non-comment line.
                foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                    $line = trim((string) preg_replace('/#.*$/', '', $line));
                    if ($line !== '') {
                        return NodeSpec::parse($line, $file);
                    }
                }
            }
        }
        $package = $read('package.json');
        if ($package !== null) {
            $json = json_decode($package, true);
            if (is_array($json)) {
                if (is_array($json['volta'] ?? null) && is_string($json['volta']['node'] ?? null) && $json['volta']['node'] !== '') {
                    return NodeSpec::parse($json['volta']['node'], 'package.json (volta.node)');
                }
                if (is_array($json['engines'] ?? null) && is_string($json['engines']['node'] ?? null) && $json['engines']['node'] !== '') {
                    return NodeSpec::parse($json['engines']['node'], 'package.json (engines.node)');
                }
            }
        }

        return null;
    }

    /**
     * NODE-04: the highest installed version satisfying the spec. Null when none
     * does and a download is needed (see fromIndex()).
     * With no spec at all: the newest installed Node, or E_NODE_NONE.
     */
    public function installed(?NodeSpec $spec): ?NodeVersion
    {
        $installed = $this->locator->installed();
        if ($spec === null) {
            if ($installed === []) {
                throw new CpdeployException(
                    ErrorCode::NODE_NONE,
                    'No Node.js is installed and no Node version is set',
                    'Set a Node version (Manage site → Node version) or add .nvmrc.',
                );
            }

            return $installed[0];
        }

        if ($spec->kind === NodeSpec::LTS) {
            $lts = $this->ltsVersions($spec->ltsName);
            foreach ($installed as $node) {
                if (in_array($node->version, $lts, true)) {
                    return $node;
                }
            }

            return null;
        }

        foreach ($installed as $node) {
            if ($spec->matches($node->version)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * NODE-04: the highest version in the release index that satisfies the spec
     * and has an official build for $arch ("x64" or "arm64").
     */
    public function fromIndex(NodeSpec $spec, string $arch): ?string
    {
        $file = 'linux-' . $arch;
        foreach ($this->index() as $release) {
            if (!in_array($file, $release['files'], true)) {
                continue;
            }
            $version = $release['version'];
            $ok = match ($spec->kind) {
                NodeSpec::LTS => $release['lts'] !== false && ($spec->ltsName === null || strtolower($release['lts']) === $spec->ltsName),
                default => $spec->matches($version),
            };
            if ($ok) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Versions that are LTS (optionally of one codename), from the index.
     *
     * @return list<string>
     */
    private function ltsVersions(?string $name): array
    {
        $out = [];
        foreach ($this->index() as $release) {
            if ($release['lts'] !== false && ($name === null || strtolower($release['lts']) === $name)) {
                $out[] = $release['version'];
            }
        }

        return $out;
    }

    /**
     * <mirror>/index.json, cached in tools/node/index.json for 24 hours. Newest first,
     * versions without the leading "v".
     *
     * @return list<array{version: string, lts: string|false, files: list<string>}>
     */
    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $cache = $this->paths->nodeDir() . '/index.json';
        $raw = null;
        if (is_file($cache) && (time() - (int) filemtime($cache)) < self::INDEX_MAX_AGE) {
            $raw = (string) file_get_contents($cache);
        } else {
            $url = rtrim($this->mirror, '/') . '/index.json';
            $response = $this->http->get($url, timeout: 60);
            if ($response->ok() && is_array(json_decode($response->body, true))) {
                $raw = $response->body;
                $this->fs->ensureDir($this->paths->toolsDir(), Paths::MODE_ROOT);
                $this->fs->ensureDir($this->paths->nodeDir(), Paths::MODE_ROOT);
                $this->fs->writeAtomic($cache, $raw, Paths::MODE_PUBLIC_FILE);
            } elseif (is_file($cache)) {
                $raw = (string) file_get_contents($cache);
            } else {
                $why = $response->status > 0 ? 'HTTP ' . $response->status : ($response->error !== '' ? $response->error : 'no response');
                throw new CpdeployException(ErrorCode::DOWNLOAD, "Couldn't download the Node.js release list from {$url} ({$why})", 'Check the network, or the mirror in Settings (mirrors.node).');
            }
        }

        return $this->index = self::parseIndex($raw);
    }

    /**
     * @return list<array{version: string, lts: string|false, files: list<string>}>
     */
    public static function parseIndex(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (!is_array($row) || !is_string($row['version'] ?? null)) {
                continue;
            }
            $version = ltrim($row['version'], 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
                continue;
            }
            $out[] = [
                'version' => $version,
                'lts' => is_string($row['lts'] ?? null) && $row['lts'] !== '' ? $row['lts'] : false,
                'files' => is_array($row['files'] ?? null) ? array_values(array_filter($row['files'], 'is_string')) : [],
            ];
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }
}
