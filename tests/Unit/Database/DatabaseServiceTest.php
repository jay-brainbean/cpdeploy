<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Database;

use Cpdeploy\Database\DatabaseService;
use PHPUnit\Framework\TestCase;

/**
 * DB-01 names and passwords.
 */
final class DatabaseServiceTest extends TestCase
{
    /**
     * @covers-req DB-01
     */
    public function testNamesArePrefixedSanitisedCutAndUnique(): void
    {
        self::assertSame('my_shop_2', DatabaseService::sanitise('My-Shop.2'));
        self::assertSame('site', DatabaseService::sanitise('---'));
        self::assertSame('cpuser_shop', DatabaseService::unique('cpuser_', 'shop', 64, []));
        self::assertSame('cpuser_shop_2', DatabaseService::unique('cpuser_', 'shop', 64, ['cpuser_shop']));
        self::assertSame('cpuser_shop_3', DatabaseService::unique('cpuser_', 'shop', 64, ['cpuser_shop', 'cpuser_shop_2']));
        // Cut to the limit, the suffix included.
        self::assertSame('cpuser_averyl', DatabaseService::unique('cpuser_', 'averylongname', 13, []));
        self::assertSame('cpuser_ave_2', DatabaseService::unique('cpuser_', 'averylongname', 12, ['cpuser_avery']));
    }

    /**
     * @covers-req DB-01
     */
    public function testPasswordsHaveEveryCharacterClass(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $password = DatabaseService::password();
            self::assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z0-9]{32}$/', $password);
        }
        self::assertSame(48, strlen(DatabaseService::password(DatabaseService::PASSWORD_RETRY_LENGTH)));
    }

    public function testMysqlEnv(): void
    {
        self::assertSame([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'cpuser_shop',
            'DB_USERNAME' => 'cpuser_u',
            'DB_PASSWORD' => 'pw',
        ], DatabaseService::mysqlEnv('cpuser_shop', 'cpuser_u', 'pw'));
    }
}
