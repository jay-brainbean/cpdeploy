<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard;

use Cpdeploy\Config\Paths;
use Cpdeploy\Cpanel\Domain;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Throwable;

/**
 * Sites set up by the old cpanel-git-setup.sh (§10.6): found in ~/deployments
 * (LEG-01), read (LEG-02), used to pre-fill the wizard (LEG-03), and cleaned up
 * after cpdeploy's first deploy — the old cron line and webhook file (LEG-04).
 * ~/deployments/<name> itself is never deleted.
 */
final class LegacyImporter
{
    public const DIR = 'deployments';
    public const CONF_KEYS = ['BRANCH', 'DEST', 'SUBDIR', 'BUILD_CMD', 'POST_CMD', 'PHP_VERSION', 'NODE_VERSION', 'COMPOSER_VERSION', 'HOOK_DIR'];

    public function __construct(
        private readonly Paths $paths,
        private readonly Shell $shell,
    ) {
    }

    /**
     * LEG-01: ~/deployments/<name>/ with deploy.conf and repo/.git.
     *
     * @return list<LegacySite>
     */
    public function find(): array
    {
        $base = $this->paths->home() . '/' . self::DIR;
        $out = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_file($dir . '/deploy.conf') || !is_dir($dir . '/repo/.git')) {
                continue;
            }
            $name = basename($dir);
            $out[] = new LegacySite(
                $name,
                $dir,
                $this->origin($dir . '/repo'),
                self::parseConf((string) file_get_contents($dir . '/deploy.conf')),
                $this->paths->sshDir() . '/deploy_' . $name,
            );
        }

        return $out;
    }

    /**
     * LEG-02: KEY=value / KEY="value" / KEY='value' lines of deploy.conf.
     *
     * @return array<string, string>
     */
    public static function parseConf(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim($m[2]);
            if (preg_match('/^"(.*)"$/s', $value, $q) === 1) {
                $value = stripcslashes($q[1]);
            } elseif (preg_match("/^'(.*)'$/s", $value, $q) === 1) {
                $value = $q[1];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $out[$m[1]] = $value;
        }

        return $out;
    }

    /**
     * LEG-03: pre-fills the wizard. Returns the lines to show the user (the old
     * BUILD_CMD / POST_CMD are shown, not translated).
     *
     * @param list<Domain> $domains
     * @return list<string>
     */
    public function prefill(LegacySite $legacy, WizardState $state, array $domains): array
    {
        $state->legacy = $legacy;
        $state->repo = $legacy->repo;
        $state->branch = $legacy->get('BRANCH');
        $state->name = $legacy->name;
        $php = $legacy->get('PHP_VERSION');
        if ($php !== null && strtolower($php) !== 'auto' && preg_match('/^(\d+)\.?(\d+)/', $php, $m) === 1) {
            $state->phpVersion = $m[1] . '.' . $m[2];
        }
        $node = $legacy->get('NODE_VERSION');
        if ($node !== null && strtolower($node) !== 'auto') {
            $state->nodeVersion = $node;
        }
        $dest = $legacy->get('DEST');
        if ($dest !== null) {
            foreach ($domains as $domain) {
                if ($domain->selectable() && Fs::normalize($domain->documentRoot) === Fs::normalize($dest)) {
                    $state->domain = $domain->name;
                    $state->docroot = $domain->documentRoot;
                    $state->ip = $domain->ip;
                }
            }
        }
        $lines = [];
        foreach (['BUILD_CMD', 'POST_CMD'] as $key) {
            $value = $legacy->get($key);
            if ($value !== null) {
                $lines[] = "The old setup ran ({$key}): {$value}";
            }
        }

        return $lines;
    }

    /**
     * LEG-04: crontab lines of the old script (marked `# git-deploy:<name>`).
     *
     * @return list<string>
     */
    public function cronLines(string $name): array
    {
        return array_values(array_filter($this->crontab(), static fn (string $l): bool => self::isOurCronLine($l, $name)));
    }

    public function removeCronLines(string $name): bool
    {
        $lines = $this->crontab();
        $kept = array_values(array_filter($lines, static fn (string $l): bool => !self::isOurCronLine($l, $name)));
        if (count($kept) === count($lines)) {
            return false;
        }
        $result = $this->shell->run(['crontab', '-'], (new RunOptions(timeout: 30, label: 'crontab'))->withInput(implode("\n", $kept) . "\n"));
        if (!$result->successful()) {
            throw new CpdeployException(ErrorCode::USAGE, "Couldn't update the crontab: " . trim($result->output()), 'Remove the line in cPanel → Cron Jobs.');
        }

        return true;
    }

    /**
     * LEG-04: the old webhook files, deploy-hook-<name>-*.php, in HOOK_DIR, DEST
     * or ~/public_html.
     *
     * @return list<string>
     */
    public function webhookFiles(LegacySite $legacy): array
    {
        $dirs = array_filter([$legacy->get('HOOK_DIR'), $legacy->get('DEST'), $this->paths->home() . '/public_html']);
        $files = [];
        foreach (array_unique($dirs) as $dir) {
            foreach (glob(rtrim($dir, '/') . '/deploy-hook-' . $legacy->name . '-*.php') ?: [] as $file) {
                if (is_file($file) && !is_link($file)) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param list<string> $files from webhookFiles()
     */
    public function removeWebhooks(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    private static function isOurCronLine(string $line, string $name): bool
    {
        return preg_match('/#\s*git-deploy:' . preg_quote($name, '/') . '\b/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function crontab(): array
    {
        try {
            $result = $this->shell->run(['crontab', '-l'], new RunOptions(timeout: 30, label: 'crontab -l'));
        } catch (Throwable) {
            return [];
        }
        if (!$result->successful()) {
            return [];
        }

        return array_values(array_filter(explode("\n", rtrim($result->stdout, "\n")), static fn (string $l): bool => $l !== ''));
    }

    private function origin(string $repo): ?RepoUrl
    {
        $result = $this->shell->run(['git', '-C', $repo, 'remote', 'get-url', 'origin'], new RunOptions(timeout: 30, label: 'git remote'));
        $url = trim($result->stdout);
        // git@github-<name>:owner/repo.git (the old script's SSH alias), or any GitHub form.
        if (preg_match('#[:/]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $url, $m) === 1) {
            try {
                return RepoUrl::parse($m[1] . '/' . $m[2]);
            } catch (CpdeployException) {
                return null;
            }
        }

        return null;
    }
}
