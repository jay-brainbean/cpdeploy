<?php

declare(strict_types=1);

namespace Cpdeploy\Docroot;

/**
 * Laravel's front-controller rules for public/.htaccess. Without them Apache and
 * LiteSpeed serve `/` (DirectoryIndex finds index.php) but answer 404 for every
 * other route. A repo that doesn't commit public/.htaccess (often ignored
 * because cPanel edits it) works with a manual upload, where the server's copy
 * stays put, but not in a fresh release. The added rules sit between markers so
 * the drift check (PRE-17) can tell them from a manual edit.
 */
final class LaravelRewrites
{
    public const BEGIN = '# cpdeploy -- BEGIN Laravel rewrite rules (public/.htaccess is not in the repo)';
    public const END = '# cpdeploy -- END Laravel rewrite rules';

    /** Laravel's default public/.htaccess. */
    private const RULES = <<<'HTACCESS'
        <IfModule mod_rewrite.c>
            <IfModule mod_negotiation.c>
                Options -MultiViews -Indexes
            </IfModule>

            RewriteEngine On

            # Handle Authorization Header
            RewriteCond %{HTTP:Authorization} .
            RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

            # Handle X-XSRF-Token Header
            RewriteCond %{HTTP:x-xsrf-token} .
            RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

            # Redirect Trailing Slashes If Not A Folder...
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_URI} (.+)/$
            RewriteRule ^ %1 [L,R=301]

            # Send Requests To Front Controller...
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^ index.php [L]
        </IfModule>
        HTACCESS;

    public static function block(): string
    {
        return self::BEGIN . "\n" . self::RULES . "\n" . self::END;
    }

    /**
     * True when $htaccess (null = no file) has no rules of its own: missing,
     * empty, comments only, or just cPanel's PHP handler block.
     */
    public static function needed(?string $htaccess): bool
    {
        return $htaccess === null || !HandlerBlock::hasOtherRules($htaccess);
    }

    /**
     * $htaccess with the rules added after any handler block.
     */
    public static function add(?string $htaccess): string
    {
        $handler = $htaccess === null ? null : HandlerBlock::capture($htaccess);

        return ($handler !== null ? $handler . "\n\n" : '') . self::block() . "\n";
    }

    /**
     * $htaccess without the block this class adds (and the blank line after it).
     */
    public static function strip(string $htaccess): string
    {
        $pattern = '/^[ \t]*' . preg_quote(self::BEGIN, '/') . '[^\n]*\n.*?^[ \t]*' . preg_quote(self::END, '/') . '[^\n]*(?:\r?\n)?(?:[ \t]*\r?\n)?/ms';

        return (string) preg_replace($pattern, '', $htaccess);
    }

    /**
     * Whether $htaccess sends unknown paths to index.php (a RewriteRule to it, or
     * FallbackResource), for `cpdeploy check`.
     */
    public static function routesToIndex(string $htaccess): bool
    {
        return preg_match('/^[ \t]*(?:RewriteRule[ \t]+\S+[ \t]+\/?index\.php\b|FallbackResource[ \t]+\/?index\.php\b)/mi', $htaccess) === 1;
    }
}
