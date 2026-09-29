<?php

declare(strict_types=1);

namespace Cpdeploy\Deploy;

use Closure;
use Cpdeploy\Config\Paths;
use Cpdeploy\Config\Schema\SiteSchema;
use Cpdeploy\Config\SiteConfig;
use Cpdeploy\Support\Fs;
use Cpdeploy\Support\Http;
use Cpdeploy\Support\HttpResponse;

/**
 * §11.6: the release marker (HC-01) and the health request (HC-02, HC-05).
 * Requests go to this server's IP for the domain (HTTP-03), so DNS elsewhere
 * (Cloudflare, not moved yet) doesn't matter. CPDEPLOY_HTTP_OVERRIDE=<host>:<port>
 * (test mode only) replaces the target and uses plain HTTP.
 */
final class HealthChecker
{
    /** Seconds between attempts (HC-02). */
    public const GAP = 5;
    /** HC-05: how long a 503 is retried while PHP workers may still serve the old release. */
    public const WORKER_WAIT = 130;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep
     */
    public function __construct(
        private readonly Http $http,
        private readonly ?string $override = null,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * HC-01: does the web server serve the new release? Null = yes; else a warning.
     */
    public function marker(SiteConfig $site, ?string $ip, string $marker, string $releaseId): ?string
    {
        $last = new HttpResponse(0, [], '');
        for ($try = 1; $try <= 3; $try++) {
            [$response] = $this->get($site->domain(), $ip, '/' . $marker, 10);
            $last = $response;
            if ($response->status === 200 && trim($response->body) === $releaseId) {
                return null;
            }
            if ($try < 3) {
                ($this->sleep)(1);
            }
        }

        return sprintf(
            'The web server is not serving the new release (%s) — check the domain\'s document root in cPanel',
            $last->status > 0 ? 'HTTP ' . $last->status . ' for the release marker' : 'no response',
        );
    }

    /**
     * HC-02 / HC-05. $maintenanceWasOn: G1 ran, so a 503 may be the old release's
     * maintenance page served from a PHP-FPM path cache (GL-02).
     *
     * @param (Closure(string): void)|null $onWait called once when waiting for PHP workers
     */
    public function check(SiteConfig $site, ?string $ip, bool $maintenanceWasOn = false, ?Closure $onWait = null): HealthResult
    {
        $ranges = SiteSchema::parseExpect($site->healthExpect()) ?? [[200, 399]];
        $attempts = 0;
        $failures = 0;
        $warnings = [];
        $waitedSince = null;
        $last = new HttpResponse(0, [], '');
        $url = '';
        $seconds = 0.0;

        while (true) {
            $attempts++;
            $started = hrtime(true);
            [$last, $url, $warning] = $this->get($site->domain(), $ip, $site->healthPath(), $site->healthTimeout());
            $seconds = (hrtime(true) - $started) / 1e9;
            if ($warning !== null && !in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
            if (self::inRanges($last->status, $ranges)) {
                return new HealthResult(true, $last->status, $seconds, $url, $attempts, $warnings);
            }
            if ($last->status === 503 && $maintenanceWasOn) {
                if ($waitedSince === null) {
                    $waitedSince = microtime(true);
                    if ($onWait !== null) {
                        $onWait('Waiting for PHP workers to pick up the new release… (up to 2 min)');
                    }
                }
                if (microtime(true) - $waitedSince < self::WORKER_WAIT) {
                    ($this->sleep)(self::GAP);
                    continue;
                }
            }
            $failures++;
            if ($failures >= $site->healthAttempts()) {
                return new HealthResult(false, $last->status, $seconds, $url, $attempts, $warnings, $last->error);
            }
            ($this->sleep)(self::GAP);
        }
    }

    /**
     * HTTP-04: which PHP version serves the site. A probe file with a random name
     * (SEC-09) is written into $webPath, requested, and deleted in every case.
     * Returns the version, or null when the site didn't answer with one.
     */
    public function servedPhp(SiteConfig $site, ?string $ip, string $webPath, Fs $fs): ?string
    {
        $name = '.cpd-probe-' . bin2hex(random_bytes(16)) . '.php';
        $file = $webPath . '/' . $name;
        try {
            $fs->writeAtomic($file, "<?php echo PHP_VERSION;\n", Paths::MODE_PUBLIC_FILE);
            [$response] = $this->get($site->domain(), $ip, '/' . $name, 15);
            $body = trim($response->body);

            return $response->status === 200 && preg_match('/^\d+\.\d+\.\d+\S*$/', $body) === 1 ? $body : null;
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * HTTPS first, HTTP when HTTPS can't connect; on a TLS error, retry with -k
     * and warn (HC-02).
     *
     * @return array{0: HttpResponse, 1: string, 2: ?string} response, URL, warning
     */
    private function get(string $domain, ?string $ip, string $path, int $timeout): array
    {
        if ($this->override !== null) {
            [$host, $port] = array_pad(explode(':', $this->override, 2), 2, '80');
            $url = "http://{$domain}:{$port}{$path}";

            return [$this->http->send('GET', $url, timeout: $timeout, resolve: ["{$domain}:{$port}:{$host}"]), $url, null];
        }

        $url = "https://{$domain}{$path}";
        $resolve = $ip !== null && $ip !== '' ? ["{$domain}:443:{$ip}"] : [];
        $response = $this->http->send('GET', $url, timeout: $timeout, resolve: $resolve);
        if ($response->tlsError()) {
            $warning = 'SSL certificate problem: ' . ($response->error !== '' ? $response->error : 'the certificate was not accepted');
            $response = $this->http->send('GET', $url, timeout: $timeout, resolve: $resolve, insecure: true);

            return [$response, $url, $warning];
        }
        if ($response->networkError()) {
            $http = "http://{$domain}{$path}";
            $fallback = $this->http->send('GET', $http, timeout: $timeout, resolve: $ip !== null && $ip !== '' ? ["{$domain}:80:{$ip}"] : []);
            if (!$fallback->networkError()) {
                return [$fallback, $http, null];
            }
        }

        return [$response, $url, null];
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private static function inRanges(int $status, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }
}
