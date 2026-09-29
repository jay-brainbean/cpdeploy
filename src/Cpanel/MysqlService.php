<?php

declare(strict_types=1);

namespace Cpdeploy\Cpanel;

/**
 * MySQL databases and users through the Mysql module (CP-04).
 */
final class MysqlService
{
    public function __construct(private readonly Uapi $uapi)
    {
    }

    /**
     * Real field names are max_database_name_length and max_username_length; the
     * names from the plan's draft are accepted too. `prefix` is absent (or empty)
     * when database prefixing is off.
     */
    public function restrictions(): MysqlRestrictions
    {
        $data = $this->uapi->call('Mysql', 'get_restrictions');
        $data = is_array($data) ? $data : [];
        $int = static function (array $data, array $keys, int $default): int {
            foreach ($keys as $key) {
                if (is_numeric($data[$key] ?? null)) {
                    return (int) $data[$key];
                }
            }

            return $default;
        };
        $prefix = is_string($data['prefix'] ?? null) && $data['prefix'] !== '' ? $data['prefix'] : null;

        return new MysqlRestrictions(
            $prefix,
            $int($data, ['max_database_name_length', 'database_name_length_limit'], 64),
            $int($data, ['max_username_length', 'database_user_name_length_limit'], 32),
        );
    }

    /**
     * @return array<string, list<string>> database => users
     */
    public function databases(): array
    {
        $out = [];
        $data = $this->uapi->call('Mysql', 'list_databases');
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && is_string($row['database'] ?? null)) {
                $out[$row['database']] = self::strings($row['users'] ?? []);
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>> user => databases
     */
    public function users(): array
    {
        $out = [];
        $data = $this->uapi->call('Mysql', 'list_users');
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && is_string($row['user'] ?? null)) {
                $out[$row['user']] = self::strings($row['databases'] ?? []);
            }
        }

        return $out;
    }

    public function createDatabase(string $name): void
    {
        $this->uapi->call('Mysql', 'create_database', ['name' => $name]);
    }

    /**
     * SEC-07: the only place a secret is passed as an argument. The caller must add
     * $password to the Masker first; it must be freshly generated and alphanumeric.
     */
    public function createUser(string $name, string $password): void
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $password) !== 1) {
            throw new \InvalidArgumentException('Generated database passwords must be alphanumeric (SEC-07)');
        }
        $this->uapi->call('Mysql', 'create_user', ['name' => $name, 'password' => $password]);
    }

    public function grantAll(string $user, string $database): void
    {
        $this->uapi->call('Mysql', 'set_privileges_on_database', [
            'user' => $user,
            'database' => $database,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }

    public function deleteDatabase(string $name): void
    {
        $this->uapi->call('Mysql', 'delete_database', ['name' => $name]);
    }

    public function deleteUser(string $name): void
    {
        $this->uapi->call('Mysql', 'delete_user', ['name' => $name]);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
