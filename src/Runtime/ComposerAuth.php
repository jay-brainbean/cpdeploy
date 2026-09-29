<?php

declare(strict_types=1);

namespace Cpdeploy\Runtime;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Lock;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Version;

/**
 * Manage site → Composer credentials (§9.5.11, CMP-06): shared/auth.json,
 * validated JSON, mode 600, never written into a release (SEC-15).
 */
final class ComposerAuth
{
    public const TEMPLATE = "{\n    \"github-oauth\": {\n        \"github.com\": \"\"\n    },\n    \"http-basic\": {\n    }\n}\n";

    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly Clock $clock,
        private readonly SystemInfo $system,
        private readonly SiteRegistry $sites,
    ) {
    }

    public function exists(string $site): bool
    {
        return is_file($this->paths->sharedAuthJson($site));
    }

    public function read(string $site): ?string
    {
        $this->sites->load($site);
        $file = $this->paths->sharedAuthJson($site);
        if (!is_file($file)) {
            return null;
        }
        @chmod($file, Paths::MODE_SECRET_FILE);

        return (string) file_get_contents($file);
    }

    /**
     * An error message, or null when $text is a JSON object.
     */
    public static function validate(string $text): ?string
    {
        $data = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'auth.json is not valid JSON: ' . json_last_error_msg();
        }
        if (!$data instanceof \stdClass) {
            return 'auth.json must be a JSON object, e.g. {"github-oauth": {"github.com": "<token>"}}';
        }

        return null;
    }

    public function save(string $site, string $text): void
    {
        $error = self::validate($text);
        if ($error !== null) {
            throw new CpdeployException(ErrorCode::USAGE, $error, 'Nothing was saved.');
        }
        $lock = $this->lock($site);
        try {
            $this->fs->ensureDir($this->paths->sharedDir($site), Paths::MODE_ROOT);
            $this->fs->writeAtomic($this->paths->sharedAuthJson($site), $text, Paths::MODE_SECRET_FILE);
        } finally {
            $lock->release();
        }
    }

    public function remove(string $site): bool
    {
        $this->sites->load($site);
        $lock = $this->lock($site);
        try {
            $file = $this->paths->sharedAuthJson($site);

            return is_file($file) && @unlink($file);
        } finally {
            $lock->release();
        }
    }

    private function lock(string $site): Lock
    {
        return Lock::site($this->paths->siteLock($site), $site, 'composer credentials', $this->system->userName(), Version::get(), $this->clock);
    }
}
