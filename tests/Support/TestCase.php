<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

use Cpdeploy\Services;
use Cpdeploy\Support\Environment;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;

/**
 * Base for every test (§16.2): a temporary HOME per test, cpdeploy's root inside
 * it via CPDEPLOY_HOME, a fake-binaries folder first on PATH, and CPDEPLOY_TESTING=1.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $tmp;
    protected string $home;
    protected string $root;
    protected string $fakeBin;

    /** @var array<string, string> */
    protected array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/cpd-test-' . bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $this->tmp = (string) realpath($base);
        $this->home = $this->tmp . '/home';
        $this->root = $this->home . '/cpdeploy';
        $this->fakeBin = $this->tmp . '/fakebin';
        mkdir($this->home, 0711, true);
        mkdir($this->fakeBin, 0755, true);

        $this->env = [
            'HOME' => $this->home,
            'CPDEPLOY_TESTING' => '1',
            'CPDEPLOY_HOME' => $this->root,
            'CPDEPLOY_TEST_PATH' => $this->fakeBin,
            'LANG' => 'C.UTF-8',
            'USER' => 'tester',
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_dir($this->tmp) && str_starts_with($this->tmp, sys_get_temp_dir())) {
            // Test clean-up only; production code never shells out like this (ARC-05).
            exec('chmod -R u+rwX ' . escapeshellarg($this->tmp) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->tmp));
        }
        parent::tearDown();
    }

    protected function environment(): Environment
    {
        return new Environment($this->env);
    }

    protected function services(): Services
    {
        return new Services($this->environment());
    }

    /**
     * Writes an executable script into the fake-binaries folder.
     */
    protected function fakeBin(string $name, string $script): string
    {
        $path = $this->fakeBin . '/' . $name;
        file_put_contents($path, $script);
        chmod($path, 0755);

        return $path;
    }

    /**
     * A fake `uapi` that answers from JSON fixtures in tests/Fixtures/uapi/<Module>/<function>.json.
     */
    protected function fakeUapi(): string
    {
        return $this->fakeBin('uapi', "#!/usr/bin/env php\n<?php\n" . <<<'PHP'
            $args = array_slice($argv, 1);
            $args = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--output')));
            [$module, $function] = [$args[0] ?? '', $args[1] ?? ''];
            $fixture = getenv('CPDEPLOY_FIXTURES') . "/uapi/{$module}/{$function}.json";
            if (is_file($fixture)) {
                echo file_get_contents($fixture);
                exit(0);
            }
            echo json_encode(['result' => ['status' => 0, 'errors' => ["No fixture for {$module}::{$function}"], 'data' => null]]);
            PHP);
    }

    /**
     * Runs bin/cpdeploy in a subprocess with only the test environment.
     *
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runCli(array $args, ?string $stdin = null, float $timeout = 60): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/cpdeploy', ...$args];
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $command[] = '--allow-root';
        }
        $env = $this->env + [
            'PATH' => $this->fakeBin . ':/usr/local/bin:/usr/bin:/bin',
            'CPDEPLOY_FIXTURES' => dirname(__DIR__) . '/Fixtures',
        ];
        // Replace the inherited environment entirely.
        foreach (array_keys(getenv()) as $key) {
            $env[$key] ??= false;
        }
        $process = new Process($command, $this->tmp, $env, $stdin, $timeout);
        $process->run();

        return [
            'exit' => (int) $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
