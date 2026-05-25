<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Run this from the command line.' . PHP_EOL;
    exit(1);
}

$password = $argv[1] ?? '';
if ($password === '') {
    echo "Usage: php tools/make-password-hash.php 'your-new-password'\n";
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
