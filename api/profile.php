<?php
require_once __DIR__ . '/../config.php';

$user = checkAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    jsonResponse('success', 'fetch profile', [
        'id' => $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
        'tambon' => $user['tambon'],
        'avatar_path' => $user['avatar_path'] ?? null,
    ]);
}

if ($method !== 'POST' || !isset($_FILES['image'])) {
    jsonResponse('error', 'Invalid request');
}

$stored = storeValidatedUploadedImage($_FILES['image'], [
    'max_bytes' => MAX_PROFILE_IMAGE_BYTES,
    'max_width' => 1200,
    'max_height' => 1200,
]);
if (empty($stored['path'])) {
    jsonResponse('error', $stored['error'] ?? 'อัปโหลดรูปโปรไฟล์ไม่สำเร็จ');
}

$oldPath = normalizeUploadPath($user['avatar_path'] ?? '');
$stmt = $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
if (!$stmt->execute([$stored['path'], $user['id']])) {
    deleteManagedUpload($stored['path']);
    jsonResponse('error', 'ไม่สามารถบันทึกรูปโปรไฟล์ได้');
}

$freshStmt = $pdo->prepare('SELECT ' . usersSelectColumns() . ' FROM users WHERE id = ? LIMIT 1');
$freshStmt->execute([$user['id']]);
$freshUser = $freshStmt->fetch() ?: array_merge($user, ['avatar_path' => $stored['path']]);
storeAuthenticatedSession($freshUser);

if ($oldPath && $oldPath !== $stored['path']) {
    deleteManagedUpload($oldPath);
}

jsonResponse('success', 'อัปเดตรูปโปรไฟล์แล้ว', ['user' => $freshUser]);
