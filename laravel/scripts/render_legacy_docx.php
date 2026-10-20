<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(64);
}

$recordId = trim((string) ($argv[1] ?? ''));
$userId = trim((string) ($argv[2] ?? ''));
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

storeAuthenticatedSession($user);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['ids' => $recordId];
require $projectRoot.'/api/export_doc.php';
