<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/teacher_directory.php';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $data = getJsonInput();
    $username = normalizeInputString($data['username'] ?? '', 50);
    $password = (string) ($data['password'] ?? '');

    if (empty($username) || empty($password)) {
        jsonResponse('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
    }

    try {
        syncTeacherUsersWithLocalDatabase($pdo);
    } catch (Throwable $e) {
        // Login should still work with the local cache if the teacher directory is temporarily unavailable.
    }

    $stmt = $pdo->prepare("SELECT " . usersSelectColumns(true) . " FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && verifyPassword($password, $user['password'])) {
        if (passwordNeedsUpgrade($user['password'])) {
            $newHash = hashPasswordIfNeeded($password);
            $upgradeStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upgradeStmt->execute([$newHash, $user['id']]);
        }
        unset($user['password']); // Don't put password in session
        storeAuthenticatedSession($user);
        jsonResponse('success', 'เข้าสู่ระบบสำเร็จ', [
            'user' => $user,
            'print_token' => generateSignedUserToken($user, 'print', 60 * 30),
        ]);
    } else {
        jsonResponse('error', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    clearAuthenticatedSession();
    jsonResponse('success', 'ออกจากระบบสำเร็จ');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    $user = checkAuth();
    $data = getJsonInput();
    $cur = $data['cur_pass'] ?? '';
    $new = $data['new_pass'] ?? '';

    if (mb_strlen((string) $new) < 6) {
        jsonResponse('error', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
    }

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $db_pass = $stmt->fetchColumn();

    if (!verifyPassword($cur, $db_pass)) {
        jsonResponse('error', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
    }

    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    if ($stmt->execute([hashPasswordIfNeeded($new), $user['id']])) {
        storeAuthenticatedSession($user);
        jsonResponse('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว', [
            'print_token' => generateSignedUserToken($user, 'print', 60 * 30),
        ]);
    } else {
        jsonResponse('error', 'เกิดข้อผิดพลาดในการบันทึกรหัสผ่าน');
    }
}

jsonResponse('error', 'Invalid action');
