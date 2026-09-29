<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Env;

use Cpdeploy\Env\EnvFile;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private const CORPUS = <<<'ENV'
        # Application
        APP_NAME="My Shop"
        APP_ENV=production
        APP_KEY=base64:abc123/def+ghi=
        APP_DEBUG=false   # never true in production
        export APP_URL=https://shop.example.com

        DB_CONNECTION=mysql
        # DB_HOST=127.0.0.1
        DB_PASSWORD='p$ss ${NOT_EXPANDED}'
        MAIL_FROM_NAME="${APP_NAME}"
        PRIVATE_KEY="-----BEGIN KEY-----
        line two \"quoted\"
        -----END KEY-----"
        EMPTY=
        SPACED = value
        ENV;

    /**
     * @covers-req ENV-01
     */
    public function testRoundTripIsByteIdentical(): void
    {
        foreach ([self::CORPUS, self::CORPUS . "\n", "A=1\r\nB=2\r\n", '', "\n\n# only comments\n"] as $text) {
            self::assertSame($text, EnvFile::parse($text)->toString());
        }
    }

    /**
     * @covers-req ENV-01
     */
    public function testValues(): void
    {
        $env = EnvFile::parse(self::CORPUS);

        self::assertSame('My Shop', $env->get('APP_NAME'));
        self::assertSame('false', $env->get('APP_DEBUG'));
        self::assertSame('https://shop.example.com', $env->get('APP_URL'));
        self::assertSame('base64:abc123/def+ghi=', $env->get('APP_KEY'));
        self::assertNull($env->get('DB_HOST'), 'commented out');
        self::assertSame('p$ss ${NOT_EXPANDED}', $env->get('DB_PASSWORD'));
        self::assertSame('${APP_NAME}', $env->get('MAIL_FROM_NAME'));
        self::assertSame("-----BEGIN KEY-----\nline two \"quoted\"\n-----END KEY-----", $env->get('PRIVATE_KEY'));
        self::assertSame('', $env->get('EMPTY'));
        self::assertSame('value', $env->get('SPACED'));
        self::assertCount(11, $env->all());
    }

    /**
     * @covers-req ENV-02
     */
    public function testDuplicatesWarnAndTheFirstWins(): void
    {
        $env = EnvFile::parse("A=1\nB=2\nA=3\n");

        self::assertSame('1', $env->get('A'));
        self::assertSame(['A is set more than once (lines 1, 3); the first one is used'], $env->warnings);
    }

    /**
     * @covers-req ENV-02
     * @covers-req PRE-11
     */
    public function testSyntaxErrorsNameTheLine(): void
    {
        foreach ([
            "A=1\nthis is not an assignment\n" => 2,
            "A=1\n1BAD=x\n" => 2,
            "A=\"never closed\nB=2\n" => 1,
            "A=two words\n" => 1,
            "A='x' trailing\n" => 1,
        ] as $text => $line) {
            try {
                EnvFile::parse($text);
                self::fail('Expected a parse error for: ' . $text);
            } catch (CpdeployException $e) {
                self::assertSame(ErrorCode::ENV_PARSE, $e->errorCode);
                self::assertStringContainsString("line {$line}", $e->getMessage());
            }
        }
    }
}
