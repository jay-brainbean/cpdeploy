<?php

declare(strict_types=1);

namespace Cpdeploy\GitHub;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;

/**
 * The optional GitHub token in ~/cpdeploy/secrets/github-token (SEC-01): file 600
 * in a 700 folder, modes re-applied before every read. Never in config.yml.
 */
final class TokenStore
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Masker $masker,
    ) {
    }

    public function has(): bool
    {
        return is_file($this->paths->tokenFile());
    }

    public function get(): ?string
    {
        $file = $this->paths->tokenFile();
        if (!is_file($file)) {
            return null;
        }
        @chmod($this->paths->secretsDir(), Paths::MODE_PRIVATE_DIR);
        @chmod($file, Paths::MODE_SECRET_FILE);
        $token = trim((string) file_get_contents($file));
        if ($token === '') {
            return null;
        }
        $this->masker->add($token);

        return $token;
    }

    public function set(string $token): void
    {
        $token = trim($token);
        if (!self::looksValid($token)) {
            throw new CpdeployException(
                ErrorCode::TOKEN_INVALID,
                "That doesn't look like a GitHub token",
                'Paste the whole token (it starts with github_pat_ for a fine-grained token).',
            );
        }
        $this->masker->add($token);
        $this->fs->ensureDir($this->paths->secretsDir(), Paths::MODE_PRIVATE_DIR);
        $this->fs->writeAtomic($this->paths->tokenFile(), $token . "\n", Paths::MODE_SECRET_FILE);
    }

    /**
     * SEC-14: overwrite first (best effort), then unlink.
     */
    public function remove(): bool
    {
        $file = $this->paths->tokenFile();
        if (!is_file($file)) {
            return false;
        }
        $size = (int) filesize($file);
        $handle = @fopen($file, 'r+');
        if ($handle !== false) {
            fwrite($handle, random_bytes(max(64, $size)));
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            fclose($handle);
        }

        return @unlink($file);
    }

    public static function looksValid(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_]{20,255}$/', $token) === 1;
    }
}
