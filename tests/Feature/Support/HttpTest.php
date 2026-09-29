<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Support;

use Cpdeploy\Support\Http;
use Cpdeploy\Tests\Support\LocalServer;
use Cpdeploy\Tests\Support\TestCase;

final class HttpTest extends TestCase
{
    private LocalServer $server;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->tmp . '/www');
        file_put_contents($this->tmp . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $count = __DIR__ . '/count';
            if ($path === '/flaky') {
                $n = (int) @file_get_contents($count) + 1;
                file_put_contents($count, (string) $n);
                http_response_code($n < 3 ? 503 : 200);
                echo "attempt {$n}";
                return;
            }
            if ($path === '/always500') { http_response_code(500); echo 'no'; return; }
            if ($path === '/missing') { http_response_code(404); echo 'nope'; return; }
            if ($path === '/redirect') { header('Location: /echo'); http_response_code(302); return; }
            if ($path === '/echo') {
                header('X-Custom: yes');
                header('Content-Type: application/json');
                echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'accept' => $_SERVER['HTTP_ACCEPT'] ?? null, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null, 'body' => file_get_contents('php://input')]);
                return;
            }
            http_response_code(404);
            PHP);
        $this->server = new LocalServer($this->tmp . '/www', $this->tmp . '/router.php');
        // Record curl's argv to prove no secret is passed on the command line (SEC-03).
        $this->fakeBin('curl', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($this->tmp . '/curl-argv') . "\nexec /usr/bin/curl \"\$@\"\n");
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function http(): Http
    {
        $s = $this->services();

        return new Http($s->shell(), $s->fs(), function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    /**
     * @covers-req HTTP-01
     * @covers-req GH-01
     * @covers-req SEC-03
     */
    public function testGetWithSecretHeaderFollowsRedirects(): void
    {
        $r = $this->http()->get($this->server->url('/redirect'), ['Accept' => 'application/vnd.github+json'], ['Authorization' => 'Bearer tok_secret_123']);

        self::assertSame(200, $r->status);
        self::assertTrue($r->ok());
        self::assertSame('yes', $r->header('X-CUSTOM'));
        $data = $r->json();
        self::assertIsArray($data);
        self::assertSame('Bearer tok_secret_123', $data['auth']);
        self::assertSame('application/vnd.github+json', $data['accept']);
        self::assertStringStartsWith('cpdeploy/', (string) $data['ua']);
        self::assertStringNotContainsString('tok_secret_123', (string) file_get_contents($this->tmp . '/curl-argv'));
    }

    /**
     * @covers-req HTTP-02
     */
    public function testGetRetriesTwiceOn5xxWithBackoff(): void
    {
        $r = $this->http()->get($this->server->url('/flaky'));

        self::assertSame(200, $r->status);
        self::assertSame('attempt 3', $r->body);
        self::assertSame(3, $r->attempts);
        self::assertSame([1, 3], $this->slept);

        $this->slept = [];
        $r = $this->http()->get($this->server->url('/always500'));
        self::assertSame(500, $r->status);
        self::assertSame(3, $r->attempts);
    }

    /**
     * @covers-req HTTP-02
     */
    public function testNoRetryOn4xxOrForOtherMethods(): void
    {
        $r = $this->http()->get($this->server->url('/missing'));
        self::assertSame(404, $r->status);
        self::assertSame(1, $r->attempts);

        file_put_contents($this->tmp . '/count', '0');
        $r = $this->http()->send('POST', $this->server->url('/flaky'), '{"a":1}');
        self::assertSame(503, $r->status);
        self::assertSame(1, $r->attempts);
        self::assertSame([], $this->slept);

        $echo = $this->http()->send('POST', $this->server->url('/echo'), '{"key":"ssh-ed25519 AAAA"}', ['Content-Type' => 'application/json']);
        $data = $echo->json();
        self::assertIsArray($data);
        self::assertSame('POST', $data['method']);
        self::assertSame('{"key":"ssh-ed25519 AAAA"}', $data['body']);
    }

    /**
     * @covers-req HTTP-02
     */
    public function testNetworkErrorIsStatus0AndRetried(): void
    {
        $this->server->stop();
        $r = $this->http()->get($this->server->url('/echo'), timeout: 3);

        self::assertTrue($r->networkError());
        self::assertSame(3, $r->attempts);
        self::assertNotSame(0, $r->curlExit);
    }

    /**
     * @covers-req HTTP-03
     */
    public function testResolvePinsTheDomainToAnIp(): void
    {
        $r = $this->http()->get('http://shop.example.test:' . $this->server->port . '/echo', resolve: ['shop.example.test:' . $this->server->port . ':127.0.0.1']);

        self::assertSame(200, $r->status);
    }

    public function testDownloadRemovesPartialFileOnFailure(): void
    {
        $file = $this->tmp . '/out.bin';
        $r = $this->http()->download($this->server->url('/missing'), $file);

        self::assertSame(404, $r->status);
        self::assertFileDoesNotExist($file);
    }

    public function testParseHeadersKeepsTheLastResponse(): void
    {
        $headers = Http::parseHeaders("HTTP/1.1 302 Found\r\nLocation: /x\r\n\r\nHTTP/2 200\r\ncontent-type: text/plain\r\nX-A: 1\r\nX-A: 2\r\n\r\n");

        self::assertSame(['content-type' => ['text/plain'], 'x-a' => ['1', '2']], $headers);
    }
}
