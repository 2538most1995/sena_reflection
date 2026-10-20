<?php
require_once __DIR__ . '/../config.php';

$user = checkAuth();
if ($user['role'] !== 'district') {
    jsonResponse('error', 'Unauthorized access');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_logo_path' LIMIT 1");
    $stmt->execute();
    jsonResponse('success', 'fetch logo', ['path' => normalizeUploadPath($stmt->fetchColumn() ?: '')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    jsonResponse('error', 'Invalid request');
}

$stored = storeValidatedUploadedImage($_FILES['image'], [
    'max_bytes' => MAX_LOGO_IMAGE_BYTES,
    'max_width' => 2400,
    'max_height' => 1200,
]);
if (empty($stored['path'])) {
    jsonResponse('error', $stored['error'] ?? 'อัปโหลดโลโก้ไม่สำเร็จ');
}

$oldStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_logo_path' LIMIT 1");
$oldStmt->execute();
$oldPath = normalizeUploadPath($oldStmt->fetchColumn() ?: '');

$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('app_logo_path', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
if (!$stmt->execute([$stored['path']])) {
    deleteManagedUpload($stored['path']);
    jsonResponse('error', 'ไม่สามารถบันทึกโลโก้ได้');
}

if ($oldPath && $oldPath !== $stored['path']) {
    deleteManagedUpload($oldPath);
}

jsonResponse('success', 'อัปเดตโลโก้แล้ว', ['path' => $stored['path']]);
