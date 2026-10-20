<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/teacher_directory.php';

$user = checkAuth();
if ($user['role'] !== 'district') {
    jsonResponse('error', 'Unauthorized access');
}

$method = $_SERVER['REQUEST_METHOD'];

function normalizeUserTambon($role, $tambon) {
    return $role === 'district' ? null : trim((string) $tambon);
}

function usersTableColumnExists(PDO $pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([(string) $column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function validateUserPayload($pdo, $data, $isUpdate = false, $currentUser = null) {
    $id = trim((string) ($data['id'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = trim((string) ($data['role'] ?? 'tambon'));

    if (!in_array($role, ['district', 'tambon'], true)) {
        jsonResponse('error', 'สิทธิ์ผู้ใช้ไม่ถูกต้อง');
    }

    $tambon = normalizeUserTambon($role, $data['tambon'] ?? '');

    if (($isUpdate && $id === '') || $name === '' || $username === '') {
        jsonResponse('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
    }

    if (!$isUpdate && $password === '') {
        jsonResponse('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
    }

    if ($password !== '' && mb_strlen($password) < 6) {
        jsonResponse('error', 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
    }

    if ($role === 'tambon' && $tambon === '') {
        jsonResponse('error', 'กรุณาเลือกตำบลสำหรับผู้ดูแลระดับตำบล');
    }

    if ($role === 'tambon') {
        $tambonStmt = $pdo->prepare("SELECT id FROM tambons WHERE id = ? LIMIT 1");
        $tambonStmt->execute([$tambon]);
        if (!$tambonStmt->fetchColumn()) {
            jsonResponse('error', 'ไม่พบตำบลที่เลือก');
        }
    }

    $dupStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $dupStmt->execute([$username, $id]);
    if ($dupStmt->fetch()) {
        jsonResponse('error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
    }

    if ($isUpdate && $currentUser && $currentUser['id'] === $id && $role !== 'district') {
        $districtCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'district'")->fetchColumn();
        if ($districtCount <= 1) {
            jsonResponse('error', 'บัญชีผู้ดูแลอำเภอหลักต้องคงสิทธิ์ผู้ดูแลอำเภอไว้อย่างน้อย 1 บัญชี');
        }
    }

    return [
        'id' => $id,
        'name' => $name,
        'username' => $username,
        'password' => $password,
        'role' => $role,
        'tambon' => $tambon,
    ];
}

// Fetch all users
if ($method === 'GET') {
    try {
        $lastSync = (int) ($_SESSION['teacher_sync_at'] ?? 0);
        if ($lastSync === 0 || (time() - $lastSync) > 300) {
            syncTeacherUsersWithLocalDatabase($pdo);
            $_SESSION['teacher_sync_at'] = time();
        }

        $canReadLastRecord = usersTableColumnExists($pdo, 'records', 'created_by')
            && usersTableColumnExists($pdo, 'records', 'created_at');
        $avatarSelect = usersTableColumnExists($pdo, 'users', 'avatar_path') ? 'u.avatar_path' : 'NULL AS avatar_path';

        if ($canReadLastRecord) {
            $stmt = $pdo->query("SELECT u.id, u.name, u.username, u.role, u.tambon, {$avatarSelect}, MAX(r.created_at) AS last_record
                FROM users u
                LEFT JOIN records r ON r.created_by COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                GROUP BY u.id, u.name, u.username, u.role, u.tambon
                ORDER BY u.role, u.tambon");
        } else {
            $stmt = $pdo->query("SELECT u.id, u.name, u.username, u.role, u.tambon, {$avatarSelect}, NULL AS last_record
                FROM users u
                ORDER BY u.role, u.tambon");
        }
    } catch (Throwable $e) {
        $avatarSelect = usersTableColumnExists($pdo, 'users', 'avatar_path') ? 'u.avatar_path' : 'NULL AS avatar_path';
        $stmt = $pdo->query("SELECT u.id, u.name, u.username, u.role, u.tambon, {$avatarSelect}, NULL AS last_record
            FROM users u
            ORDER BY u.role, u.tambon");
    }

    $users = $stmt->fetchAll();
    jsonResponse('success', 'fetch users', $users);
}

// Create new user
if ($method === 'POST') {
    $data = getJsonInput();
    $payload = validateUserPayload($pdo, $data);

    $id = generateId('u');
    $stmt = $pdo->prepare("INSERT INTO users (id, name, username, password, role, tambon) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$id, $payload['name'], $payload['username'], hashPasswordIfNeeded($payload['password']), $payload['role'], $payload['tambon']])) {
        jsonResponse('success', 'เพิ่มผู้ดูแลเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error creating user');
    }
}

// Update user
if ($method === 'PUT') {
    $data = getJsonInput();
    if (!isset($data['id'])) {
        jsonResponse('error', 'Missing user ID');
    }
    $payload = validateUserPayload($pdo, $data, true, $user);

    $existsStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $existsStmt->execute([$payload['id']]);
    if (!$existsStmt->fetchColumn()) {
        jsonResponse('error', 'ไม่พบบัญชีผู้ใช้ที่ต้องการแก้ไข');
    }

    $fields = [
        'name = ?',
        'username = ?',
        'role = ?',
        'tambon = ?'
    ];
    $params = [$payload['name'], $payload['username'], $payload['role'], $payload['tambon']];

    if (!empty($payload['password'])) {
        $fields[] = 'password = ?';
        $params[] = hashPasswordIfNeeded($payload['password']);
    }

    $params[] = $payload['id'];
    $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
    if ($stmt->execute($params)) {
        jsonResponse('success', 'แก้ไขข้อมูลเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error updating user');
    }
}

// Delete user
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        jsonResponse('error', 'Missing user ID');
    }
    
    // Prevent deleting self or district admin
    if ($id === $user['id']) {
        jsonResponse('error', 'Cannot delete your own account');
    }
    
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'district'");
    if ($stmt->execute([$id])) {
        jsonResponse('success', 'ลบผู้ดูแลเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error deleting user');
    }
}

jsonResponse('error', 'Invalid Method');
