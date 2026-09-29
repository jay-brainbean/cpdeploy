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
            'CPDEPLOY_HTTP_NO_BACKOFF' => '1',
            // Network probes never leave the machine: port 1 on localhost is closed.
            'CPDEPLOY_TCP_OVERRIDE' => '*=127.0.0.1:1',
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
     * A fake `uapi` (§16.2). It answers from, in order:
     *   <tmp>/uapi/<Module>/<function>.(txt|json)   per-test overrides (txt = raw output)
     *   tests/Fixtures/uapi/<Module>/<function>.json captured from a real server
     * Every call is appended to <tmp>/uapi-calls.jsonl with its arguments decoded
     * (and, when CPD_WATCH_LINK names a symlink, where it pointed at that moment).
     * php_set_vhost_versions is remembered in <tmp>/multiphp.json and shows up in
     * later php_get_vhost_versions answers.
     * Calls listed in <tmp>/uapi-fail ("Module::function" per line) return status 0.
     */
    protected function fakeUapi(): string
    {
        $this->env['CPDEPLOY_FIXTURES'] = dirname(__DIR__) . '/Fixtures';
        $this->env['CPD_UAPI_STATE'] = $this->tmp;

        return $this->fakeBin('uapi', "#!/usr/bin/env php\n<?php\n" . <<<'PHP'
            $args = array_slice($argv, 1);
            $args = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--output')));
            [$module, $function] = [$args[0] ?? '', $args[1] ?? ''];
            $params = [];
            foreach (array_slice($args, 2) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $params[$k] = rawurldecode($v);
            }
            $state = getenv('CPD_UAPI_STATE');
            $watch = getenv('CPD_WATCH_LINK');
            $record = ['call' => "{$module}::{$function}", 'args' => $params];
            if ($watch !== false && $watch !== '') {
                $record['link'] = is_link($watch) ? readlink($watch) : null;
            }
            file_put_contents("{$state}/uapi-calls.jsonl", json_encode($record) . "\n", FILE_APPEND);
            $multiphp = "{$state}/multiphp.json";
            $fail = is_file("{$state}/uapi-fail") ? array_map('trim', file("{$state}/uapi-fail")) : [];
            if (in_array("{$module}::{$function}", $fail, true)) {
                fwrite(STDERR, "[fake] warn [uapi] refused\n");
                echo json_encode(['result' => ['status' => 0, 'errors' => ["Fake failure of {$module}::{$function}"], 'data' => null]]);
                exit(0);
            }
            if ("{$module}::{$function}" === 'LangPHP::php_set_vhost_versions') {
                // MultiPHP is stateful: later php_get_vhost_versions calls see the new version.
                $over = is_file($multiphp) ? json_decode(file_get_contents($multiphp), true) : [];
                $over[$params['vhost'] ?? ''] = $params['version'] ?? '';
                file_put_contents($multiphp, json_encode($over));
            }
            foreach (["{$state}/uapi/{$module}/{$function}.txt", "{$state}/uapi/{$module}/{$function}.json", getenv('CPDEPLOY_FIXTURES') . "/uapi/{$module}/{$function}.json"] as $file) {
                if (is_file($file)) {
                    $out = file_get_contents($file);
                    if ("{$module}::{$function}" === 'LangPHP::php_get_vhost_versions' && is_file($multiphp)) {
                        $over = json_decode(file_get_contents($multiphp), true);
                        $doc = json_decode($out, true);
                        foreach ($doc['result']['data'] as &$row) {
                            if (isset($over[$row['vhost']])) {
                                $row['version'] = $over[$row['vhost']];
                                $row['phpversion_source'] = ['domain' => $row['vhost']];
                            }
                        }
                        unset($row);
                        $out = json_encode($doc);
                    }
                    echo $out;
                    exit(0);
                }
            }
            $writes = ['php_set_vhost_versions', 'create_database', 'create_user', 'set_privileges_on_database', 'delete_database', 'delete_user'];
            if (in_array($function, $writes, true)) {
                echo json_encode(['result' => ['status' => 1, 'errors' => null, 'data' => null]]);
                exit(0);
            }
            echo json_encode(['result' => ['status' => 0, 'errors' => ["No fixture for {$module}::{$function}"], 'data' => null]]);
            PHP);
    }

    /**
     * Per-test uapi answer; $body is written verbatim.
     */
    protected function uapiFixture(string $module, string $function, string $body, bool $raw = false): void
    {
        $dir = $this->tmp . '/uapi/' . $module;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $function . ($raw ? '.txt' : '.json'), $body);
    }

    protected function uapiFail(string ...$calls): void
    {
        file_put_contents($this->tmp . '/uapi-fail', implode("\n", $calls) . "\n");
    }

    /**
     * @return list<array{call: string, args: array<string, string>, link?: ?string}>
     */
    protected function uapiCalls(): array
    {
        $file = $this->tmp . '/uapi-calls.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $calls = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $call = json_decode($line, true);
            if (is_array($call)) {
                /** @var array{call: string, args: array<string, string>, link?: ?string} $call */
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * A fake PHP binary (§16.2) at <root>/ea-phpNN/root/usr/bin/php (or, for alt,
     * <root>/phpNN/usr/bin/php). It reports $version / $sapi / $modules to the
     * probes, and runs everything else with the real PHP and CPD_FAKE_PHP=<tag>
     * set, so tests can tell which binary ran.
     *
     * @param list<string> $modules
     */
    protected function fakePhp(string $root, string $version, string $family = 'ea', array $modules = ['Core', 'ctype', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml'], string $sapi = 'cli'): string
    {
        [$major, $minor] = explode('.', $version);
        $tag = $family . '-php' . $major . $minor;
        $path = $family === 'alt' ? "{$root}/php{$major}{$minor}/usr/bin/php" : "{$root}/ea-php{$major}{$minor}/root/usr/bin/php";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $config = var_export(['version' => $version, 'sapi' => $sapi, 'modules' => $modules, 'tag' => $tag, 'real' => PHP_BINARY], true);
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n\$fake = {$config};\n" . <<<'PHP'
            $args = array_slice($argv, 1);
            if (($args[0] ?? '') === '-m') {
                echo "[PHP Modules]\n", implode("\n", $fake['modules']), "\n\n[Zend Modules]\nZend OPcache\n";
                exit(0);
            }
            if (($args[0] ?? '') === '-r' && isset($args[1])) {
                [$ma, $mi, $pa] = array_map('intval', explode('.', $fake['version']) + [0, 0, 0]);
                $args[1] = strtr($args[1], [
                    'PHP_VERSION_ID' => (string) ($ma * 10000 + $mi * 100 + $pa),
                    'PHP_VERSION' => var_export($fake['version'], true),
                    'PHP_SAPI' => var_export($fake['sapi'], true),
                    'PHP_BINARY' => var_export(__FILE__, true),
                ]);
            }
            putenv('CPD_FAKE_PHP=' . $fake['tag']);
            passthru(escapeshellarg($fake['real']) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $code);
            exit($code);
            PHP);
        chmod($path, 0755);

        return $path;
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
