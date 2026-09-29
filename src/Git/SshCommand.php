<?php

declare(strict_types=1);

namespace Cpdeploy\Git;

use Cpdeploy\Support\Shell;

/**
 * GIT_SSH_COMMAND for one site's git network calls (GIT-02). `-F /dev/null`
 * ignores the user's ~/.ssh/config; only cpdeploy's known_hosts is trusted.
 */
final class SshCommand
{
    public static function build(string $privateKey, string $knownHosts): string
    {
        return implode(' ', [
            'ssh', '-F', '/dev/null',
            '-i', Shell::quote($privateKey),
            '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . Shell::quote($knownHosts),
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=20',
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=4',
        ]);
    }
}
