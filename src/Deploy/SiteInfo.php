<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Cpdeploy\Config\Paths;
use Cpdeploy\Config\SiteRegistry;
use Cpdeploy\Docroot\DocrootManager;
use Cpdeploy\Git\DeployKeyService;
use Cpdeploy\Runtime\PhpChange;
use Cpdeploy\Support\Fs;
use Cpdeploy\Ui\Format;

/**
 * Manage site → Site info (§9.5.13): paths, versions, disk use (on request,
 * it can be slow), database and deploy key.
 */
final class SiteInfo
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Fs $fs,
        private readonly SiteRegistry $sites,
        private readonly ReleaseManager $releases,
        private readonly DocrootManager $docroots,
        private readonly PhpChange $php,
        private readonly DeployKeyService $keys,
    ) {
    }

    /**
     * Sections of label => value.
     *
     * @return array<string, array<string, string>>
     */
    public function facts(string $site, bool $sizes = false): array
    {
        $config = $this->sites->load($site);
        $live = $this->releases->live($site);
        $docroot = $config->docroot();
        $target = $this->docroots->symlinkTarget($docroot);
        $php = $this->php->overview($site);

        $out = [
            'Paths' => [
                'Docroot' => $docroot . ($target !== null ? ' → ' . $target : (is_dir($docroot) ? ' (a folder: converted at the first deploy)' : ' (missing)')),
                'Site folder' => $this->paths->siteFilesDir($site),
                'Releases' => $this->paths->releasesDir($site),
                'Shared' => $this->paths->sharedDir($site),
                'Repository copy' => $this->paths->mirror($site),
                'Settings and logs' => $this->paths->siteDir($site),
            ],
            'Versions' => [
                'Site PHP' => $php['Site setting'] ?? '',
                'Domain PHP' => $php['Domain now'] ?? '',
                'Live release PHP' => $live !== null ? (string) $live->get('php.version') : '—',
                'Node' => $config->nodeVersion() . ($live !== null && is_string($live->get('node.version')) ? ' (live: ' . $live->get('node.version') . ')' : ''),
                'Composer' => $config->composerVersion() . ($live !== null && is_string($live->get('composer.version')) ? ' (live: ' . $live->get('composer.version') . ')' : ''),
            ],
        ];
        if ($config->isLaravel()) {
            $out['Versions']['Laravel'] = $live !== null && is_string($live->get('laravel')) ? (string) $live->get('laravel') : '—';
        }
        if ($sizes) {
            $out['Disk use'] = [
                'Releases' => $this->size($this->paths->releasesDir($site)),
                'Shared' => $this->size($this->paths->sharedDir($site)),
                'Repository copy' => $this->size($this->paths->mirror($site)),
            ];
        }
        $database = $config->get('database.name');
        $user = $config->get('database.user');
        $out['Database'] = [
            'Name' => is_string($database) && $database !== '' ? $database : '— (see .env)',
            'User' => is_string($user) && $user !== '' ? $user : '—',
            'Created by cpdeploy' => $config->get('database.created_by_cpdeploy') === true ? 'yes' : 'no',
        ];
        $out['GitHub'] = [
            'Repository' => $config->repo()->fullName() . ' (' . $config->branch() . ')',
            'Transport' => $config->transport() === 'ssh443' ? 'SSH over port 443' : 'SSH (port 22)',
            'Deploy key' => $this->keys->exists($site) ? (string) ($this->keys->fingerprint($site) ?? $this->keys->keyPath($site)) : 'missing: ' . $this->keys->keyPath($site),
            'Key id on GitHub' => $config->deployKeyId() !== null ? (string) $config->deployKeyId() : 'added by hand',
        ];

        return $out;
    }

    private function size(string $path): string
    {
        if (!file_exists($path)) {
            return '—';
        }
        $kb = $this->fs->diskUsageKb($path);

        return $kb === null ? 'unknown' : Format::kilobytes($kb);
    }
}
