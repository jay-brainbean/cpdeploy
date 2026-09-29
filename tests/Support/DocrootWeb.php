<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Support;

/**
 * The "web" fake (§16.2): PHP's built-in server whose document root is
 * re-resolved on every request, the way Apache follows the docroot symlink.
 * Static files are served as they are; everything else goes to index.php.
 */
final class DocrootWeb
{
    public static function start(string $tmp, string $docroot): LocalServer
    {
        $router = $tmp . '/web-router.php';
        file_put_contents($router, '<?php' . "\n" . '$docroot = ' . var_export($docroot, true) . ";\n" . <<<'PHP'
            clearstatcache(true);
            $uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path = $docroot . $uri;
            if ($uri !== '/' && is_file($path) && !str_ends_with($path, '.php')) {
                header('Content-Type: text/plain');
                readfile($path);
                return true;
            }
            $index = $docroot . '/index.php';
            if (is_file($index)) {
                $real = (string) realpath($index);
                $_SERVER['SCRIPT_FILENAME'] = $real;
                $_SERVER['SCRIPT_NAME'] = '/index.php';
                $_SERVER['DOCUMENT_ROOT'] = dirname($real);
                chdir(dirname($real));
                require $real;
                return true;
            }
            http_response_code(404);
            echo 'not found';
            return true;
            PHP);
        $empty = $tmp . '/web-empty';
        if (!is_dir($empty)) {
            mkdir($empty);
        }

        return new LocalServer($empty, $router);
    }
}
