<?php

declare(strict_types=1);
// Fixture front controller: the status can be forced through storage/app/.fake-status.
$root = dirname(__DIR__);
if (is_file($root . '/storage/framework/down')) {
    http_response_code(503);
    echo 'maintenance';
    return;
}
$status = @file_get_contents($root . '/storage/app/.fake-status');
if ($status !== false && trim($status) !== '') {
    http_response_code((int) trim($status));
}
echo 'shop ok ' . basename($root);
