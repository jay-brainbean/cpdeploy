<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use Cpdeploy\Support\Environment;

/**
 * Status symbols (UI-04) with the ASCII fallback (UI-07).
 */
final class Theme
{
    private const UNICODE = [
        'ok' => '✓', 'fail' => '✗', 'warn' => '⚠', 'question' => '?', 'cursor' => '❯',
        'live' => '●', 'other' => '○', 'rolled_back' => '↺', 'info' => '·', 'dash' => '–',
        'ellipsis' => '…', 'arrow' => '→', 'back' => '←', 'mask' => '••••',
    ];

    private const ASCII = [
        'ok' => '[ok]', 'fail' => '[x]', 'warn' => '[!]', 'question' => '[?]', 'cursor' => '>',
        'live' => '*', 'other' => '-', 'rolled_back' => '<', 'info' => '-', 'dash' => '-',
        'ellipsis' => '...', 'arrow' => '->', 'back' => '<-', 'mask' => '****',
    ];

    private const SPINNER_UNICODE = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const SPINNER_ASCII = ['|', '/', '-', '\\'];

    public function __construct(public readonly bool $unicode = true)
    {
    }

    /**
     * UI-07: ASCII unless LANG/LC_ALL/LC_CTYPE mention UTF-8, or when forced by ui.unicode.
     */
    public static function detect(Environment $env, ?bool $configured = null): self
    {
        if ($configured !== null) {
            return new self($configured);
        }
        foreach (['LC_ALL', 'LC_CTYPE', 'LANG'] as $var) {
            $value = $env->get($var);
            if ($value !== null && preg_match('/utf-?8/i', $value) === 1) {
                return new self(true);
            }
        }

        return new self(false);
    }

    public function symbol(string $name): string
    {
        $set = $this->unicode ? self::UNICODE : self::ASCII;

        return $set[$name] ?? $name;
    }

    public function spinnerFrame(int $tick): string
    {
        $frames = $this->unicode ? self::SPINNER_UNICODE : self::SPINNER_ASCII;

        return $frames[$tick % count($frames)];
    }

    /**
     * Symbol + Symfony console style for a check status (ok / warn / fail / info).
     */
    public function status(string $status): string
    {
        $style = match ($status) {
            'ok' => 'fg=green',
            'warn' => 'fg=yellow',
            'fail' => 'fg=red',
            default => 'fg=gray',
        };

        return sprintf('<%s>%s</>', $style, $this->symbol($status));
    }
}
