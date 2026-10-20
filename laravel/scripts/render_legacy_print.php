<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(64);
}

$recordId = trim((string) ($argv[1] ?? ''));
$userId = trim((string) ($argv[2] ?? ''));
$hideAttendance = (string) ($argv[3] ?? '') === '1';

if ($recordId === '' || $userId === '') {
    fwrite(STDERR, "Missing record or user id\n");
    exit(64);
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

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'id' => $recordId,
    'html_preview' => '1',
    'access_token' => generateSignedUserToken($user, 'print', 300),
];
if ($hideAttendance) {
    $_GET['hide_attendance'] = '1';
}

define('SENA_PRINT_CAPTURE_HTML', true);
define('SENA_PRINT_CAPTURE_LOCAL_ASSETS', true);

$html = require $projectRoot.'/print.php';
if (! is_string($html) || trim($html) === '') {
    fwrite(STDERR, "Legacy print renderer returned no HTML\n");
    exit(70);
}

fwrite(STDOUT, $html);
