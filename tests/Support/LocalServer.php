<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

use RuntimeException;

/**
 * PHP's built-in web server on 127.0.0.1, for fake mirrors and APIs (§16.2).
 */
final class LocalServer
{
    /** @var resource */
    private $process;
    public readonly int $port;

    /**
     * @param string      $docroot folder served
     * @param string|null $router  optional router script
     */
    public function __construct(string $docroot, ?string $router = null)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $docroot];
        if ($router !== null) {
            $cmd[] = $router;
        }
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start php -S');
        }
        $this->process = $process;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException('php -S did not start');
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
