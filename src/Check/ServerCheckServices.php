<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

use Cpdeploy\Config\GlobalConfig;
use Cpdeploy\Cpanel\CloudLinux;
use Cpdeploy\Cpanel\DomainService;
use Cpdeploy\Cpanel\MultiPhpService;
use Cpdeploy\Cpanel\MysqlService;
use Cpdeploy\Cpanel\QuotaService;
use Cpdeploy\Cpanel\Uapi;
use Cpdeploy\Git\HostKeys;
use Cpdeploy\GitHub\TokenService;
use Cpdeploy\Runtime\PhpLocator;
use Cpdeploy\Support\Clock;
use Cpdeploy\Support\TcpProbe;

/**
 * What the cPanel, Account and Network groups of `check` need.
 */
final class ServerCheckServices
{
    public function __construct(
        public readonly Uapi $uapi,
        public readonly DomainService $domains,
        public readonly MultiPhpService $multiPhp,
        public readonly MysqlService $mysql,
        public readonly QuotaService $quota,
        public readonly CloudLinux $cloudLinux,
        public readonly PhpLocator $phpLocator,
        public readonly TcpProbe $probe,
        public readonly GlobalConfig $config,
        public readonly bool $hasToken,
        public readonly TokenService $tokens,
        public readonly HostKeys $hostKeys,
        public readonly Clock $clock,
    ) {
    }
}
