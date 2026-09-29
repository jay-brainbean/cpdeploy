<?php

declare(strict_types=1);
// Fake composer.phar for cpdeploy scenario tests (plan §16.2). It runs with the
// PHP that cpdeploy chose (CPD_FAKE_PHP tells which) and simulates install,
// dump-autoload and check-platform-reqs.
$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';

function autoloadDump(): void
{
    if (is_file('artisan')) {
        // Composer scripts call "@php artisan …": `php` must be the site PHP shim (CMP-02).
        passthru('php artisan package:discover --ansi', $code);
        if ($code !== 0) {
            exit($code);
        }
    }
}

if (in_array('--version', $args, true)) {
    echo "Composer version 2.8.12 2025-09-19 13:41:59\n";
    exit(0);
}

switch ($cmd) {
    case 'check-platform-reqs':
        if (getenv('CPD_FAKE_PLATFORM_FAIL')) {
            echo json_encode([['name' => 'ext-intl', 'version' => null, 'status' => 'missing', 'failed_requirement' => ['source' => '__root__', 'type' => 'requires', 'target' => 'ext-intl', 'constraint' => '*'], 'provider' => null]]);
            exit(2);
        }
        echo json_encode([['name' => 'php', 'version' => '8.2.31', 'status' => 'success', 'failed_requirement' => null, 'provider' => null]]);
        exit(0);

    case 'install':
        if (!is_file('composer.lock')) {
            fwrite(STDERR, "No composer.lock\n");
            exit(1);
        }
        if (getenv('CPD_FAKE_COMPOSER_FAIL')) {
            echo "Your requirements could not be resolved to an installable set of packages.\n";
            exit(2);
        }
        @mkdir('vendor/composer', 0755, true);
        file_put_contents('vendor/autoload.php', "<?php // fake autoloader\n");
        file_put_contents('vendor/composer/installed.json', json_encode([
            'installed_by' => getenv('CPD_FAKE_PHP'),
            'composer_auth' => getenv('COMPOSER_AUTH') !== false,
            'args' => $args,
        ]));
        echo "Installing dependencies from lock file\n  - Installing laravel/framework (v12.31.0)\n";
        autoloadDump();
        exit(0);

    case 'dump-autoload':
        if (!is_dir('vendor/composer')) {
            fwrite(STDERR, "No vendor folder\n");
            exit(1);
        }
        file_put_contents('vendor/composer/dumped.txt', getcwd() . ' ' . getenv('CPD_FAKE_PHP') . "\n");
        echo "Generating optimized autoload files\n";
        autoloadDump();
        exit(0);

    default:
        fwrite(STDERR, "fake composer: unknown command {$cmd}\n");
        exit(1);
}
