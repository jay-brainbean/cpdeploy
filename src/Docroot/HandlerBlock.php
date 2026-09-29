<?php

declare(strict_types=1);

namespace Cpdeploy\Docroot;

/**
 * The cPanel PHP handler block in .htaccess (DOC-04): capture, rewrite for
 * another PHP, inject into a release's .htaccess, strip (for drift checks).
 */
final class HandlerBlock
{
    public const BEGIN = '# php -- BEGIN cPanel-generated handler, do not edit';
    public const END = '# php -- END cPanel-generated handler, do not edit';

    /**
     * The block between the BEGIN and END marker lines, markers included, exactly
     * as written; null when there is none.
     */
    public static function capture(string $htaccess): ?string
    {
        $pattern = '/^[ \t]*' . preg_quote(self::BEGIN, '/') . '[^\n]*\n.*?^[ \t]*' . preg_quote(self::END, '/') . '[^\n]*$/ms';
        if (preg_match($pattern, $htaccess, $m) !== 1) {
            return null;
        }

        return rtrim($m[0], "\r");
    }

    /**
     * $htaccess without any cPanel handler block (and the blank line after it).
     */
    public static function strip(string $htaccess): string
    {
        $pattern = '/^[ \t]*' . preg_quote(self::BEGIN, '/') . '[^\n]*\n.*?^[ \t]*' . preg_quote(self::END, '/') . '[^\n]*(?:\r?\n)?(?:[ \t]*\r?\n)?/ms';

        return (string) preg_replace($pattern, '', $htaccess);
    }

    /**
     * Points the block at another PHP: the package token (ea|alt)-phpNN becomes the
     * site's family and version, and the ".phpN" extension follows the major version.
     * LiteSpeed suffixes such as ___lsphp are kept.
     */
    public static function rewrite(string $block, string $family, string $majorMinor): string
    {
        $digits = str_replace('.', '', $majorMinor);
        $major = explode('.', $majorMinor)[0];
        $block = (string) preg_replace('/\b(?:ea|alt)-php\d{2}/', $family . '-php' . $digits, $block);

        return (string) preg_replace('/\.php\d\b/', '.php' . $major, $block);
    }

    /**
     * Removes any existing block from $htaccess (null = no file), then puts $block
     * plus one blank line at the top.
     */
    public static function inject(?string $htaccess, string $block): string
    {
        $rest = $htaccess === null ? '' : ltrim(self::strip($htaccess), "\r\n");

        return rtrim($block, "\r\n") . "\n\n" . $rest;
    }

    /**
     * Rules besides the handler block (for the conversion notice, DOC-02).
     */
    public static function hasOtherRules(string $htaccess): bool
    {
        foreach (preg_split('/\r?\n/', self::strip($htaccess)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                return true;
            }
        }

        return false;
    }
}
