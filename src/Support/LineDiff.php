<?php

declare(strict_types=1);

namespace Cpdeploy\Support;

/**
 * A small line diff (longest common subsequence) for short files such as
 * .htaccess (DOC-05). No external `diff` binary is needed.
 */
final class LineDiff
{
    /** Larger inputs are not diffed line by line. */
    public const MAX_LINES = 2000;

    /**
     * "- old line" / "+ new line" / "  same line", with $context unchanged lines
     * around each change and "…" between distant changes. Null when too large.
     *
     * @return list<string>|null
     */
    public static function lines(string $old, string $new, int $context = 2): ?array
    {
        $a = self::split($old);
        $b = self::split($new);
        $n = count($a);
        $m = count($b);
        if ($n > self::MAX_LINES || $m > self::MAX_LINES) {
            return null;
        }

        // LCS lengths from the end.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        /** @var list<array{0: string, 1: string}> $ops */
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($i < $n && ($j >= $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $ops[] = ['-', $a[$i]]; // removals before additions, like diff -u
                $i++;
            } else {
                $ops[] = ['+', $b[$j]];
                $j++;
            }
        }

        // Keep changes plus $context lines around them.
        $keep = [];
        foreach ($ops as $k => [$op]) {
            if ($op !== ' ') {
                for ($c = max(0, $k - $context); $c <= min(count($ops) - 1, $k + $context); $c++) {
                    $keep[$c] = true;
                }
            }
        }
        $out = [];
        $last = -1;
        foreach ($ops as $k => [$op, $text]) {
            if (!isset($keep[$k])) {
                continue;
            }
            if ($last !== -1 && $k > $last + 1) {
                $out[] = '…';
            }
            $out[] = $op . ' ' . $text;
            $last = $k;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        if ($text === '') {
            return [];
        }

        return explode("\n", rtrim($text, "\n"));
    }
}
