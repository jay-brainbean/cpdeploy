<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Env;

use Cpdeploy\Env\EnvCreator;
use Cpdeploy\Env\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * ENV-09 and DB-03.
 */
final class EnvCreatorTest extends TestCase
{
    /**
     * @covers-req ENV-09
     */
    public function testFromTheExampleWithProductionValues(): void
    {
        $example = "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n\n# Mail\nMAIL_MAILER=log\nDB_CONNECTION=sqlite\n";
        $text = EnvCreator::create($example, 'My Shop', 'https://shop.example.com', 'base64:abc=', [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_DATABASE' => 'cpuser_shop',
        ]);
        $env = EnvFile::parse($text);

        self::assertSame('My Shop', $env->get('APP_NAME'));
        self::assertSame('production', $env->get('APP_ENV'));
        self::assertSame('false', $env->get('APP_DEBUG'));
        self::assertSame('base64:abc=', $env->get('APP_KEY'));
        self::assertSame('https://shop.example.com', $env->get('APP_URL'));
        self::assertSame('mysql', $env->get('DB_CONNECTION'));
        self::assertSame('cpuser_shop', $env->get('DB_DATABASE'));
        self::assertSame('log', $env->get('MAIL_MAILER'), 'other keys of the example are kept');
        self::assertStringContainsString('# Mail', $text, 'and its comments');
    }

    /**
     * @covers-req ENV-09
     */
    public function testWithoutAnExampleTheTemplateIsUsed(): void
    {
        $env = EnvFile::parse(EnvCreator::create(null, 'Shop', 'https://s.test', 'base64:k=', []));

        self::assertSame('Shop', $env->get('APP_NAME'));
        self::assertSame('production', $env->get('APP_ENV'));
        self::assertSame('mysql', $env->get('DB_CONNECTION'));
        self::assertSame('stack', $env->get('LOG_CHANNEL'));
    }

    /**
     * @covers-req DB-03
     */
    public function testSqliteCommentsOutTheUnusedKeys(): void
    {
        $text = EnvCreator::applyDatabase("DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=\n", [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/home/u/cpdeploy/sites/shop/shared/database/database.sqlite',
        ]);
        $env = EnvFile::parse($text);

        self::assertSame('sqlite', $env->get('DB_CONNECTION'));
        self::assertSame('/home/u/cpdeploy/sites/shop/shared/database/database.sqlite', $env->get('DB_DATABASE'));
        foreach (EnvCreator::SQLITE_UNUSED as $key) {
            self::assertFalse($env->has($key), $key);
            self::assertStringContainsString('# ' . $key . '=', $text);
        }
    }
}
