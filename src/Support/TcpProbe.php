<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Tests whether TCP ports can be reached, all at once (GIT-04, `check` Network group).
 */
final class TcpProbe
{
    /**
     * @param array<string, string> $overrides test hook: "host:port" => "ip:port" ("*" matches any)
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    /**
     * CPDEPLOY_TCP_OVERRIDE (test mode only): "github.com:22=127.0.0.1:4022,*=127.0.0.1:9".
     */
    public static function fromEnvironment(Environment $env): self
    {
        $map = [];
        foreach (array_filter(explode(',', $env->testing('CPDEPLOY_TCP_OVERRIDE') ?? '')) as $pair) {
            [$from, $to] = array_pad(explode('=', $pair, 2), 2, '');
            if ($from !== '' && $to !== '') {
                $map[trim($from)] = trim($to);
            }
        }

        return new self($map);
    }

    /**
     * @param list<string> $targets "host:port"
     * @return array<string, bool> target => reachable
     */
    public function reachable(array $targets, float $timeout = 5.0): array
    {
        $pending = [];
        $result = [];
        foreach ($targets as $target) {
            $result[$target] = false;
            $address = $this->overrides[$target] ?? $this->overrides['*'] ?? $target;
            $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket !== false) {
                $pending[$target] = $socket;
            }
        }

        $deadline = microtime(true) + $timeout;
        while ($pending !== [] && ($left = $deadline - microtime(true)) > 0) {
            $write = array_values($pending);
            $read = null;
            $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6)) === false) {
                break;
            }
            foreach ($write as $socket) {
                $target = array_search($socket, $pending, true);
                if ($target === false) {
                    continue;
                }
                // Writable means the connect finished; a peer name means it succeeded.
                $result[$target] = @stream_socket_get_name($socket, true) !== false;
                fclose($socket);
                unset($pending[$target]);
            }
        }
        foreach ($pending as $socket) {
            fclose($socket);
        }

        return $result;
    }
}
