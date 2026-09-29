<?php

declare(strict_types=1);

namespace Cpdeploy\Database;

use Cpdeploy\Config\Paths;
use Cpdeploy\Cpanel\MysqlRestrictions;
use Cpdeploy\Cpanel\MysqlService;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Masker;

/**
 * A site's database (§7.13): new MySQL database and user through cPanel
 * (DB-01), an existing one checked with the site PHP (DB-02, DB-04), SQLite in
 * shared/ (DB-03), and removal (DB-05).
 */
final class DatabaseService
{
    public const PASSWORD_LENGTH = 32;
    public const PASSWORD_RETRY_LENGTH = 48;

    private ?MysqlRestrictions $restrictions = null;

    public function __construct(
        private readonly MysqlService $mysql,
        private readonly DbCheck $check,
        private readonly Masker $masker,
        private readonly Paths $paths,
        private readonly Fs $fs,
    ) {
    }

    public function restrictions(): MysqlRestrictions
    {
        return $this->restrictions ??= $this->mysql->restrictions();
    }

    /**
     * DB-01: `<prefix><site>` for both, lowercase [a-z0-9_], cut to cPanel's
     * limits, made unique against the existing databases and users (_2, _3, …).
     *
     * @return array{0: string, 1: string} database, user
     */
    public function names(string $site): array
    {
        $limits = $this->restrictions();
        $base = self::sanitise($site);
        $databases = array_keys($this->mysql->databases());
        $users = array_keys($this->mysql->users());

        return [
            self::unique((string) $limits->prefix, $base, $limits->maxDatabaseNameLength, $databases),
            self::unique((string) $limits->prefix, $base, $limits->maxUserNameLength, $users),
        ];
    }

    public static function sanitise(string $site): string
    {
        $name = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($site));
        $name = trim($name, '_');

        return $name === '' ? 'site' : $name;
    }

    /**
     * @param list<string> $taken
     */
    public static function unique(string $prefix, string $base, int $max, array $taken): string
    {
        for ($i = 1; $i < 1000; $i++) {
            $suffix = $i === 1 ? '' : '_' . $i;
            $room = max(1, $max - strlen($prefix) - strlen($suffix));
            $name = $prefix . substr($base, 0, $room) . $suffix;
            if (!in_array($name, $taken, true)) {
                return $name;
            }
        }

        return $prefix . substr(bin2hex(random_bytes(4)), 0, max(1, $max - strlen($prefix)));
    }

    /**
     * DB-01 step 3: [A-Za-z0-9], at least one lowercase, uppercase and digit.
     */
    public static function password(int $length = self::PASSWORD_LENGTH): string
    {
        $sets = ['abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '0123456789'];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public function createDatabase(string $name): void
    {
        $this->mysql->createDatabase($name);
    }

    /**
     * Creates the user with a fresh password (retried once with 48 characters when
     * cPanel calls it weak) and returns the password. SEC-07: it is masked first.
     */
    public function createUser(string $name): string
    {
        $password = self::password();
        $this->masker->add($password);
        try {
            $this->mysql->createUser($name, $password);
        } catch (CpdeployException $e) {
            if (preg_match('/weak|strength|strong/i', $e->getMessage()) !== 1) {
                throw $e;
            }
            $password = self::password(self::PASSWORD_RETRY_LENGTH);
            $this->masker->add($password);
            $this->mysql->createUser($name, $password);
        }

        return $password;
    }

    public function grant(string $user, string $database): void
    {
        $this->mysql->grantAll($user, $database);
    }

    /**
     * DB-05 (and undoing a failed Create): the database, then the user.
     */
    public function drop(?string $database, ?string $user): void
    {
        if ($database !== null) {
            $this->mysql->deleteDatabase($database);
        }
        if ($user !== null) {
            $this->mysql->deleteUser($user);
        }
    }

    /**
     * @return array<string, list<string>> database => users with access
     */
    public function existing(): array
    {
        return $this->mysql->databases();
    }

    /**
     * DB-03: shared/database/database.sqlite (600), created empty. Returns its path.
     */
    public function sqlite(string $site): string
    {
        $dir = $this->paths->sharedDir($site) . '/database';
        $this->fs->ensureDir($this->paths->sharedDir($site), Paths::MODE_ROOT);
        $this->fs->ensureDir($dir, Paths::MODE_PUBLIC_DIR);
        $file = $dir . '/database.sqlite';
        if (!is_file($file)) {
            $this->fs->writeAtomic($file, '', Paths::MODE_SECRET_FILE);
        }

        return $file;
    }

    /**
     * DB-04 with the site PHP.
     *
     * @param array<string, string> $env
     */
    public function test(string $phpBinary, array $env, string $appDir): DbCheckResult
    {
        return $this->check->check($phpBinary, $env, $appDir);
    }

    /**
     * The .env values for a MySQL database (DB-01 step 6).
     *
     * @return array<string, string>
     */
    public static function mysqlEnv(string $database, string $user, string $password): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
        ];
    }
}
