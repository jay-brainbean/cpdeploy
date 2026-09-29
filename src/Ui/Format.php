<?php

declare(strict_types=1);

namespace Cpdeploy\Ui;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Human-readable durations, sizes and times (UI-05, UI-06) and label truncation (§5.3).
 */
final class Format
{
    /** Laravel Prompts' guidance for labels on an 80-column terminal. */
    public const LABEL_WIDTH = 74;

    /**
     * 38s · 1m 41s · 1h 2m
     */
    public static function duration(float $seconds): string
    {
        $s = (int) round($seconds);
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        }

        return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    }

    /**
     * Base 1024: 512 B · 4.0 KB · 12.3 MB · 1.2 GB
     */
    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return $i === 0 ? ((int) $bytes) . ' B' : sprintf('%.1f %s', $bytes, $units[$i]);
    }

    public static function kilobytes(int $kb): string
    {
        return self::bytes($kb * 1024);
    }

    /**
     * "just now" · "5m ago" · "2h ago" · "3d ago" · "in 4d"
     */
    public static function relative(DateTimeInterface $when, DateTimeInterface $now): string
    {
        $diff = $now->getTimestamp() - $when->getTimestamp();
        $future = $diff < 0;
        $d = abs($diff);
        $text = match (true) {
            $d < 45 => null,
            $d < 3600 => max(1, (int) round($d / 60)) . 'm',
            $d < 86400 => (int) floor($d / 3600) . 'h',
            $d < 86400 * 60 => (int) floor($d / 86400) . 'd',
            $d < 86400 * 730 => (int) floor($d / (86400 * 30)) . 'mo',
            default => (int) floor($d / (86400 * 365)) . 'y',
        };
        if ($text === null) {
            return 'just now';
        }

        return $future ? 'in ' . $text : $text . ' ago';
    }

    /**
     * Absolute time in the server's local timezone, for detail screens (UI-05).
     */
    public static function local(DateTimeInterface $when): string
    {
        return DateTimeImmutable::createFromInterface($when)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i');
    }

    /**
     * Cuts $text to $width display columns, ending with an ellipsis when cut.
     */
    public static function truncate(string $text, int $width = self::LABEL_WIDTH, string $ellipsis = '…'): string
    {
        if (mb_strwidth($text) <= $width) {
            return $text;
        }

        return rtrim(mb_strimwidth($text, 0, $width - mb_strwidth($ellipsis))) . $ellipsis;
    }
}
