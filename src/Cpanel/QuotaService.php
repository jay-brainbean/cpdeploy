<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * Quota::get_quota_info. Real output mixes numbers and numeric strings
 * ("megabyte_limit": "0.00"); a limit of 0 means unlimited.
 */
final class QuotaService
{
    public function __construct(private readonly Uapi $uapi)
    {
    }

    public function quota(): Quota
    {
        $data = $this->uapi->call('Quota', 'get_quota_info');
        $data = is_array($data) ? $data : [];
        $num = static fn (string $key): float => is_numeric($data[$key] ?? null) ? (float) $data[$key] : 0.0;

        $mbLimit = $num('megabyte_limit');
        $inodeLimit = (int) $num('inode_limit');

        return new Quota(
            $num('megabytes_used'),
            $mbLimit > 0 ? $mbLimit : null,
            (int) $num('inodes_used'),
            $inodeLimit > 0 ? $inodeLimit : null,
        );
    }
}
