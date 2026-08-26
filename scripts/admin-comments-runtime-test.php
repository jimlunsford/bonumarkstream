<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!function_exists('bms_site_timezone')) {
    function bms_site_timezone(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }
}

if (!function_exists('bms_admin_url')) {
    function bms_admin_url(string $path = ''): string
    {
        return '/admin/' . ltrim($path, '/');
    }
}

require_once dirname(__DIR__) . '/admin/_comments-ui.php';

$formatted = bms_comments_admin_date('2026-08-05 21:31:50');
if ($formatted !== 'Aug 5, 2026 9:31 PM') {
    fwrite(STDERR, "Unexpected comment date output: {$formatted}\n");
    exit(1);
}

if (bms_comments_admin_status_class('trash') !== 'trash') {
    fwrite(STDERR, "Unexpected comment status class.\n");
    exit(1);
}

if (bms_comments_admin_return_url('pending', 'jim') !== '/admin/comments.php?status=pending&q=jim') {
    fwrite(STDERR, "Unexpected comment return URL.\n");
    exit(1);
}

restore_error_handler();
fwrite(STDOUT, "Admin comments runtime helper test passed.\n");
