<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    /**
     * @covers-req ARC-09
     */
    public function testExitCodesFollowSection103(): void
    {
        $expected = [
            'E_ROOT' => 2, 'E_CONFIG_INVALID' => 2, 'E_NEEDS_ANSWER' => 2, 'E_REF_NOT_FOUND' => 2,
            'E_NOT_CPANEL' => 3, 'E_GIT_AUTH' => 3, 'E_DISK' => 3, 'E_APP_KEY' => 3, 'E_DOCROOT_UNSAFE' => 3,
            'E_EXPORT' => 4, 'E_COMPOSER' => 4, 'E_OOM' => 4, 'E_TIMEOUT' => 4, 'E_ARTISAN' => 4,
            'E_MIGRATE' => 5, 'E_MULTIPHP' => 6, 'E_GOLIVE' => 6, 'E_DOCROOT_MOVE' => 6,
            'E_HEALTH' => 7, 'E_ROLLBACK' => 8, 'E_LOCKED' => 10, 'E_INTERRUPTED' => 11,
            'E_CANCELLED' => 130, 'E_UPDATE' => 1,
        ];
        foreach ($expected as $code => $exit) {
            self::assertSame($exit, ErrorCode::from($code)->exitCode(), $code);
        }
    }

    public function testEveryCatalogueCodeExists(): void
    {
        $catalogue = ['E_ROOT', 'E_NOT_CPANEL', 'E_CONFIG_INVALID', 'E_CONFIG_NEWER', 'E_LOCKED', 'E_INTERRUPTED',
            'E_NEEDS_ANSWER', 'E_GIT_AUTH', 'E_GIT_HOSTKEY', 'E_GIT_NET', 'E_GIT', 'E_REF_NOT_FOUND', 'E_BRANCH_GONE',
            'E_SUBMODULES', 'E_LFS', 'E_EXPORT', 'E_SHARED', 'E_TOKEN_INVALID', 'E_TOKEN_PERMS', 'E_GITHUB_RATE',
            'E_GITHUB_DOWN', 'E_UAPI', 'E_PHP_MISSING', 'E_PLATFORM', 'E_NO_LOCK', 'E_DOWNLOAD', 'E_CHECKSUM',
            'E_COMPOSER', 'E_NODE_SPEC', 'E_NODE_NONE', 'E_NODE_ARCH', 'E_GLIBC_OLD', 'E_PM_UNSUPPORTED',
            'E_NODE_INSTALL', 'E_NODE_BUILD', 'E_BUILD_OUTPUT', 'E_OOM', 'E_TIMEOUT', 'E_ARTISAN', 'E_CUSTOM',
            'E_ENV_MISSING', 'E_ENV_PARSE', 'E_APP_KEY', 'E_DB_CONNECT', 'E_DB_DRIVER', 'E_DISK', 'E_INODES',
            'E_DOCROOT_UNSAFE', 'E_DOCROOT_MOVE', 'E_MIGRATE', 'E_MULTIPHP', 'E_GOLIVE', 'E_HEALTH', 'E_ROLLBACK',
            'E_CANCELLED', 'E_UPDATE'];
        $defined = array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases());
        foreach ($catalogue as $code) {
            self::assertContains($code, $defined);
        }
    }

    public function testExplicitExitCodeOverridesTheDefault(): void
    {
        $e = new CpdeployException(ErrorCode::UAPI, 'cPanel refused', exitCode: 6, liveAffected: true);

        self::assertSame(6, $e->exitCode());
        self::assertTrue($e->liveAffected);
        self::assertSame('/x.log', $e->withLogPath('/x.log')->logPath);
        self::assertSame(6, $e->withLogPath('/x.log')->exitCode());
    }
}
