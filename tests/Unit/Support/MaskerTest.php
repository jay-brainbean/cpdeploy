<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Clock;
use Cpdeploy\Support\Log;
use Cpdeploy\Support\Masker;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Tests\Support\TestCase;

final class MaskerTest extends TestCase
{
    /**
     * @covers-req LOG-04
     * @covers-req SEC-02
     */
    public function testMasksEverySecretType(): void
    {
        $masker = new Masker();
        $masker->add('github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz');
        $masker->addEnv([
            'APP_KEY' => 'base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTIzNDU2Nzg=',
            'DB_PASSWORD' => 'Sup3r$ecret!',
            'MAIL_PASSWORD' => 'mailpass99',
            'APP_NAME' => 'Laravel',
        ]);
        $masker->addComposerAuth('{"github-oauth":{"github.com":"ghp_composerTokenValue123"},"http-basic":{"repo.example.com":{"username":"bob","password":"hunter2hunter2"}}}');

        $text = implode("\n", [
            'token=github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz',
            'key base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTIzNDU2Nzg=',
            'db Sup3r$ecret! and ' . rawurlencode('Sup3r$ecret!'),
            'mail mailpass99',
            'composer ghp_composerTokenValue123 hunter2hunter2',
            'Authorization: Bearer some-other-token-value',
            'authorization: token abc123',
            'Authorization: rawvalue',
            'fetching https://alice:p4ssw0rd@repo.example.com/x.git',
            'app Laravel',
        ]);
        $masked = $masker->mask($text);

        foreach ([
            'github_pat_11ABCDEFG', 'c2VjcmV0c2Vj', 'Sup3r$ecret!', rawurlencode('Sup3r$ecret!'), 'mailpass99',
            'ghp_composerTokenValue123', 'hunter2hunter2', 'some-other-token-value', 'abc123', 'rawvalue', 'p4ssw0rd', 'alice:',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $masked, "Leaked: {$secret}");
        }
        self::assertStringContainsString('Authorization: Bearer ' . Masker::MASK, $masked);
        self::assertStringContainsString('https://' . Masker::MASK . '@repo.example.com', $masked);
        self::assertStringContainsString('app Laravel', $masked, 'Non-secret values stay readable');
    }

    /**
     * @covers-req LOG-04
     */
    public function testShortValuesAreNotMaskedToKeepOutputReadable(): void
    {
        $masker = new Masker();
        $masker->addEnv(['AUTH_GUARD' => 'web']);

        self::assertSame('serving the web', $masker->mask('serving the web'));
    }

    public function testSecretKeyPatternMatchesEnv05(): void
    {
        foreach (['DB_PASSWORD', 'APP_KEY', 'AWS_SECRET_ACCESS_KEY', 'STRIPE_TOKEN', 'SENTRY_DSN', 'PRIVATE_THING', 'SOME_CREDENTIALS', 'OAUTH_ID', 'db_pass'] as $key) {
            self::assertTrue(Masker::isSecretKey($key), $key);
        }
        foreach (['APP_NAME', 'DB_HOST', 'APP_URL', 'QUEUE_CONNECTION'] as $key) {
            self::assertFalse(Masker::isSecretKey($key), $key);
        }
    }

    /**
     * Secrets printed by a command never reach the operation log or the reporter.
     *
     * @covers-req LOG-04
     * @covers-req SH-04
     */
    public function testSecretsInCommandOutputNeverReachTheLogOrTerminal(): void
    {
        $services = $this->services();
        $masker = $services->masker();
        $masker->add('tok_live_1234567890');
        $masker->addEnv(['DB_PASSWORD' => 'dbpass-very-secret']);

        $logDir = $this->tmp . '/logs';
        $log = Log::open($logDir, 'test', $masker, new Clock(), ['site' => 'shop']);
        $shell = $services->shell()->withLog($log);
        $seen = [];
        $shell->run(
            ['sh', '-c', 'echo "token tok_live_1234567890"; echo "pw dbpass-very-secret" >&2; echo "Authorization: Bearer xyzxyzxyz"'],
            (new RunOptions())->streaming(static function (string $line) use (&$seen): void {
                $seen[] = $line;
            }),
        );
        $shell->run(['echo', 'argv tok_live_1234567890']);
        $log->close('success', 0);

        $logText = (string) file_get_contents($log->path);
        $screen = implode("\n", $seen);
        foreach (['tok_live_1234567890', 'dbpass-very-secret', 'xyzxyzxyz'] as $secret) {
            self::assertStringNotContainsString($secret, $logText);
            self::assertStringNotContainsString($secret, $screen);
        }
        self::assertStringContainsString(Masker::MASK, $logText);
    }
}
