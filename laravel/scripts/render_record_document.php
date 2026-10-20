<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(64);
}

$recordId = trim((string) ($argv[1] ?? ''));
$userId = trim((string) ($argv[2] ?? ''));
$editor = (string) ($argv[3] ?? '') === '1';

if ($recordId === '' || $userId === '') {
    fwrite(STDERR, "Missing record, user or document payload\n");
    exit(64);
}

$json = stream_get_contents(STDIN);
$payload = $json !== false ? json_decode($json, true) : null;
if (! is_array($payload) || ! is_array($payload['settings'] ?? null) || ! is_array($payload['content'] ?? null)) {
    fwrite(STDERR, "Invalid document payload\n");
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
    'id' => $recordId,
    'html_preview' => '1',
    'record_document' => '1',
    'access_token' => generateSignedUserToken($user, 'print', 300),
];
if ($editor) {
    $_GET['record_document_editor'] = '1';
}
$GLOBALS['SENA_RECORD_DOCUMENT_PAYLOAD'] = $payload;

define('SENA_PRINT_CAPTURE_HTML', true);
define('SENA_PRINT_CAPTURE_LOCAL_ASSETS', ! $editor);

$html = require $projectRoot.'/print.php';
if (! is_string($html) || trim($html) === '') {
    fwrite(STDERR, "Document renderer returned no HTML\n");
    exit(70);
}

fwrite(STDOUT, $html);
