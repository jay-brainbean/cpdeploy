<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;

/**
 * Installed Node candidates (NODE-03): ea-nodejs, alt-nodejs, nvm installs and
 * the official builds cpdeploy downloaded into tools/node. Versions come from
 * `node -v`. Cached for the run.
 */
final class NodeLocator
{
    /** @var list<NodeVersion>|null */
    private ?array $cache = null;

    /**
     * @param list<string> $patterns glob patterns of bin folders
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly array $patterns,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function defaultPatterns(string $home, string $toolsNodeDir): array
    {
        return [
            '/opt/cpanel/ea-nodejs*/bin',
            '/opt/alt/alt-nodejs*/root/usr/bin',
            $home . '/.nvm/versions/node/v*/bin',
            $toolsNodeDir . '/node-v*-linux-*/bin',
        ];
    }

    /**
     * Newest first; one entry per version (the first location found wins).
     *
     * @return list<NodeVersion>
     */
    public function installed(bool $refresh = false): array
    {
        if ($this->cache !== null && !$refresh) {
            return $this->cache;
        }
        $found = [];
        foreach ($this->patterns as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $node = $dir . '/node';
                if (!is_file($node) || !is_executable($node)) {
                    continue;
                }
                $result = $this->shell->run([$node, '-v'], new RunOptions(timeout: 15, label: 'node -v'));
                if ($result->successful() && preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($result->stdout), $m) === 1) {
                    $found[$m[1]] ??= new NodeVersion($m[1], $dir);
                }
            }
        }
        uksort($found, static fn (string $a, string $b): int => version_compare($b, $a));

        return $this->cache = array_values($found);
    }
}
