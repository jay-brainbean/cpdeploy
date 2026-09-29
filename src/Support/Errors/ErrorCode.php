<?php

declare(strict_types=1);

namespace Cpdeploy\Support\Errors;

/**
 * Every user-facing error (§13). The case value is the code shown in logs and JSON.
 */
enum ErrorCode: string
{
    case ROOT = 'E_ROOT';
    case NOT_CPANEL = 'E_NOT_CPANEL';
    case CONFIG_INVALID = 'E_CONFIG_INVALID';
    case CONFIG_NEWER = 'E_CONFIG_NEWER';
    case LOCKED = 'E_LOCKED';
    case INTERRUPTED = 'E_INTERRUPTED';
    case NEEDS_ANSWER = 'E_NEEDS_ANSWER';
    case GIT_AUTH = 'E_GIT_AUTH';
    case GIT_HOSTKEY = 'E_GIT_HOSTKEY';
    case GIT_NET = 'E_GIT_NET';
    case GIT = 'E_GIT';
    case REF_NOT_FOUND = 'E_REF_NOT_FOUND';
    case BRANCH_GONE = 'E_BRANCH_GONE';
    case REWIND = 'E_REWIND';
    case SUBMODULES = 'E_SUBMODULES';
    case LFS = 'E_LFS';
    case EXPORT = 'E_EXPORT';
    case SHARED = 'E_SHARED';
    case TOKEN_INVALID = 'E_TOKEN_INVALID';
    case TOKEN_PERMS = 'E_TOKEN_PERMS';
    case GITHUB_RATE = 'E_GITHUB_RATE';
    case GITHUB_DOWN = 'E_GITHUB_DOWN';
    case UAPI = 'E_UAPI';
    case PHP_MISSING = 'E_PHP_MISSING';
    case PLATFORM = 'E_PLATFORM';
    case NO_LOCK = 'E_NO_LOCK';
    case DOWNLOAD = 'E_DOWNLOAD';
    case CHECKSUM = 'E_CHECKSUM';
    case COMPOSER = 'E_COMPOSER';
    case NODE_SPEC = 'E_NODE_SPEC';
    case NODE_NONE = 'E_NODE_NONE';
    case NODE_ARCH = 'E_NODE_ARCH';
    case GLIBC_OLD = 'E_GLIBC_OLD';
    case PM_UNSUPPORTED = 'E_PM_UNSUPPORTED';
    case NODE_INSTALL = 'E_NODE_INSTALL';
    case NODE_BUILD = 'E_NODE_BUILD';
    case BUILD_OUTPUT = 'E_BUILD_OUTPUT';
    case OOM = 'E_OOM';
    case TIMEOUT = 'E_TIMEOUT';
    case ARTISAN = 'E_ARTISAN';
    case CUSTOM = 'E_CUSTOM';
    case ENV_MISSING = 'E_ENV_MISSING';
    case ENV_PARSE = 'E_ENV_PARSE';
    case APP_KEY = 'E_APP_KEY';
    case DB_CONNECT = 'E_DB_CONNECT';
    case DB_DRIVER = 'E_DB_DRIVER';
    case DISK = 'E_DISK';
    case INODES = 'E_INODES';
    case DOCROOT_UNSAFE = 'E_DOCROOT_UNSAFE';
    case DOCROOT_MOVE = 'E_DOCROOT_MOVE';
    case MIGRATE = 'E_MIGRATE';
    case MULTIPHP = 'E_MULTIPHP';
    case GOLIVE = 'E_GOLIVE';
    case HEALTH = 'E_HEALTH';
    case ROLLBACK = 'E_ROLLBACK';
    case CANCELLED = 'E_CANCELLED';
    case UPDATE = 'E_UPDATE';
    case USAGE = 'E_USAGE';
    case INTERNAL = 'E_INTERNAL';

    /**
     * Default exit code (§10.3). Codes listed as "3/4" or "3/6" in §13 use the
     * lower value here; the thrower passes an explicit exit code for the other case.
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::ROOT, self::CONFIG_INVALID, self::CONFIG_NEWER, self::NEEDS_ANSWER,
            self::REF_NOT_FOUND, self::USAGE => 2,
            self::EXPORT, self::SHARED, self::COMPOSER, self::NODE_INSTALL, self::NODE_BUILD,
            self::BUILD_OUTPUT, self::OOM, self::TIMEOUT, self::ARTISAN, self::CUSTOM => 4,
            self::MIGRATE => 5,
            self::DOCROOT_MOVE, self::MULTIPHP, self::GOLIVE => 6,
            self::HEALTH => 7,
            self::ROLLBACK => 8,
            self::LOCKED => 10,
            self::INTERRUPTED => 11,
            self::CANCELLED => 130,
            self::UPDATE, self::INTERNAL => 1,
            default => 3,
        };
    }
}
