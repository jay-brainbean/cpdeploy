<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * Reads process environment variables. Test-only variables (§16.2) are ignored
 * unless CPDEPLOY_TESTING=1, so they can never change behaviour on a real server.
 */
final class Environment
{
    /** @var array<string, string> */
    private readonly array $vars;

    /**
     * @param array<string, string>|null $vars defaults to the real process environment
     */
    public function __construct(?array $vars = null)
    {
        if ($vars === null) {
            $vars = [];
            foreach (getenv() as $key => $value) {
                $vars[(string) $key] = (string) $value;
            }
        }
        $this->vars = $vars;
    }

    public function get(string $name): ?string
    {
        $value = $this->vars[$name] ?? null;

        return $value === '' ? null : $value;
    }

    public function isTesting(): bool
    {
        return ($this->vars['CPDEPLOY_TESTING'] ?? '') === '1';
    }

    /**
     * A test-only variable: returns null outside test mode.
     */
    public function testing(string $name): ?string
    {
        return $this->isTesting() ? $this->get($name) : null;
    }

    public function home(): string
    {
        $home = $this->get('HOME');
        if ($home === null && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            $home = is_array($info) ? $info['dir'] : null;
        }

        return rtrim($home ?? '/', '/');
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->vars;
    }
}
