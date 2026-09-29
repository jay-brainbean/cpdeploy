<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Deploy;

use Cpdeploy\Config\Presets;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Deploy\HealthChecker;
use Cpdeploy\Tests\Support\LocalServer;
use Cpdeploy\Tests\Support\TestCase;

final class HealthCheckerTest extends TestCase
{
    private LocalServer $server;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Answers with the statuses listed in statuses.txt, one per request.
        file_put_contents($this->tmp . '/statuses.txt', '');
        file_put_contents($this->tmp . '/router.php', '<?php' . "\n" . '$file = ' . var_export($this->tmp . '/statuses.txt', true) . ";\n" . <<<'PHP'
            $list = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
            $status = (int) (array_shift($list) ?? 200);
            file_put_contents($file, implode("\n", $list));
            if ($_SERVER['REQUEST_URI'] === '/.cpd-release-abc.txt') {
                echo '20260929-120000';
                return true;
            }
            http_response_code($status ?: 200);
            echo 'status ' . $status;
            return true;
            PHP);
        mkdir($this->tmp . '/www');
        $this->server = new LocalServer($this->tmp . '/www', $this->tmp . '/router.php');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    /**
     * @param list<int> $statuses
     */
    private function answers(array $statuses): void
    {
        file_put_contents($this->tmp . '/statuses.txt', implode("\n", $statuses));
    }

    /**
     * @param array<string, mixed> $health
     */
    private function site(array $health = []): SiteConfig
    {
        return new SiteConfig(SiteSchema::withDefaults([
            'name' => 'shop',
            'domain' => ['name' => 'shop.example.test'],
            'health_check' => $health + ['attempts' => 3, 'timeout' => 5],
        ], (new Presets())->for('laravel')));
    }

    private function checker(): HealthChecker
    {
        return new HealthChecker($this->services()->http(), '127.0.0.1:' . $this->server->port, function (int $s): void {
            $this->slept[] = $s;
        });
    }

    /**
     * @covers-req HC-02
     * @covers-req HTTP-03
     */
    public function testAttemptsFiveSecondsApartUntilTheStatusMatches(): void
    {
        $this->answers([500, 502, 200]);

        $result = $this->checker()->check($this->site(), null);

        self::assertTrue($result->ok);
        self::assertSame(200, $result->status);
        self::assertSame(3, $result->attempts);
        self::assertSame([5, 5], $this->slept);
        self::assertSame('http://shop.example.test:' . $this->server->port . '/', $result->url);
        self::assertMatchesRegularExpression('/^health: 200 in \d+\.\ds$/', $result->note());
    }

    /**
     * @covers-req HC-02
     * @covers-req VAL-10
     */
    public function testFailureAfterTheAttemptsAndCustomExpect(): void
    {
        $this->answers([500, 500, 500]);
        $result = $this->checker()->check($this->site(), null);
        self::assertFalse($result->ok);
        self::assertSame(500, $result->status);
        self::assertSame(3, $result->attempts);

        $this->answers([301]);
        self::assertFalse($this->checker()->check($this->site(['expect' => '200', 'attempts' => 1]), null)->ok);
        $this->answers([301]);
        self::assertTrue($this->checker()->check($this->site(['expect' => '200,301', 'attempts' => 1]), null)->ok);
    }

    /**
     * @covers-req HC-05
     */
    public function test503IsRetriedWhileWorkersMayServeTheOldRelease(): void
    {
        $this->answers([503, 503, 503, 503, 200]);
        $waiting = [];

        $result = $this->checker()->check($this->site(['attempts' => 1]), null, true, function (string $line) use (&$waiting): void {
            $waiting[] = $line;
        });

        self::assertTrue($result->ok);
        self::assertSame(5, $result->attempts);
        self::assertSame(['Waiting for PHP workers to pick up the new release… (up to 2 min)'], $waiting);

        // Without maintenance mode, a 503 is just a failure.
        $this->answers([503]);
        self::assertFalse($this->checker()->check($this->site(['attempts' => 1]), null)->ok);
    }

    /**
     * @covers-req HC-01
     */
    public function testReleaseMarker(): void
    {
        self::assertNull($this->checker()->marker($this->site(), null, '.cpd-release-abc.txt', '20260929-120000'));
        $warning = $this->checker()->marker($this->site(), null, '.cpd-release-abc.txt', '20260929-999999');
        self::assertStringContainsString('not serving the new release', (string) $warning);
    }
}
