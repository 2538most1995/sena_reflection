<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(64);
}

$userId = trim((string) ($argv[1] ?? ''));
$encoded = (string) ($argv[2] ?? '');
if ($userId === '' || $encoded === '') {
    fwrite(STDERR, "Missing user or settings\n");
    exit(64);
}

$json = base64_decode(strtr($encoded, '-_', '+/'), true);
$settings = $json !== false ? json_decode($json, true) : null;
if (! is_array($settings)) {
    fwrite(STDERR, "Invalid settings\n");
    exit(65);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot.'/config.php';

$stmt = $pdo->prepare('SELECT '.usersSelectColumns().' FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (! is_array($user)) {
    fwrite(STDERR, "User not found\n");
    exit(66);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [
    'settings_preview' => '1',
    'html_preview' => '1',
    'access_token' => generateSignedUserToken($user, 'print', 300),
];
$GLOBALS['SENA_SETTINGS_PREVIEW_PAYLOAD'] = $settings;

define('SENA_PRINT_CAPTURE_HTML', true);
define('SENA_PRINT_CAPTURE_LOCAL_ASSETS', true);

$html = require $projectRoot.'/print.php';
if (! is_string($html) || trim($html) === '') {
    fwrite(STDERR, "Preview renderer returned no HTML\n");
    exit(70);
}

fwrite(STDOUT, $html);
