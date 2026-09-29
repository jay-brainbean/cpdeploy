<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Support\Fs;
use RuntimeException;

/**
 * CMP-02: a per-operation bin folder placed first on PATH, so `php` and `@php`
 * in Composer scripts and custom commands use the **site** PHP (BLD-01):
 *   php      → symlink to the site PHP binary
 *   composer → #!/bin/sh exec "<site-php>" "<phar>" "$@"
 */
final class Shims
{
    private function __construct(
        public readonly string $dir,
        private readonly Fs $fs,
    ) {
    }

    public static function create(Fs $fs, string $phpBinary, ?string $composerPhar): self
    {
        $dir = $fs->tempDir('shims');
        if (!@symlink($phpBinary, $dir . '/php')) {
            throw new RuntimeException("Cannot create the php shim in {$dir}");
        }
        if ($composerPhar !== null) {
            $script = "#!/bin/sh\nexec " . self::shQuote($phpBinary) . ' ' . self::shQuote($composerPhar) . " \"\$@\"\n";
            file_put_contents($dir . '/composer', $script);
            chmod($dir . '/composer', 0700);
        }

        return new self($dir, $fs);
    }

    public function remove(): void
    {
        if (is_dir($this->dir)) {
            $this->fs->deleteTree($this->dir);
        }
    }

    private static function shQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
