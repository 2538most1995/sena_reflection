<?php
require_once __DIR__ . '/../config.php';

// Public GET access to load tambons, auth for modifications
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM tambons ORDER BY id");
    $tambons = $stmt->fetchAll();
    jsonResponse('success', 'fetch tambons', $tambons);
}

// User role check for writing
$user = checkAuth();
if ($user['role'] !== 'district') {
    jsonResponse('error', 'Unauthorized access');
}

if ($method === 'POST') {
    $data = getJsonInput();
    $name = normalizeInputString($data['name'] ?? '', 100);
    if ($name === '') {
        jsonResponse('error', 'กรุณากรอกชื่อกลุ่มเรียน');
    }
    
    // Support both legacy tNN ids and numeric ids that mirror usernames.
    $stmt = $pdo->query("SELECT id FROM tambons");
    $numericIds = [];
    $legacyIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
        if (ctype_digit((string) $existingId)) {
            $numericIds[] = (int) $existingId;
        } elseif (preg_match('/^t(\d+)$/i', (string) $existingId, $matches)) {
            $legacyIds[] = (int) $matches[1];
        }
    }

    if ($numericIds) {
        $newId = (string) (max($numericIds) + 1);
    } else {
        $max = $legacyIds ? max($legacyIds) : 0;
        $newId = 't' . str_pad($max + 1, 2, '0', STR_PAD_LEFT);
    }
    
    $icon = normalizeInputString(!empty($data['icon']) ? $data['icon'] : '📍', 20);
    
    $stmt = $pdo->prepare("INSERT INTO tambons (id, name, icon) VALUES (?, ?, ?)");
    if ($stmt->execute([$newId, $name, $icon])) {
        jsonResponse('success', 'เพิ่มกลุ่มเรียนเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error creating tambon');
    }
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $id = normalizeInputString($data['id'] ?? '', 20);
    $name = normalizeInputString($data['name'] ?? '', 100);
    if ($id === '' || $name === '') {
        jsonResponse('error', 'ข้อมูลไม่ครบถ้วน');
    }
    
    $icon = normalizeInputString(!empty($data['icon']) ? $data['icon'] : '📍', 20);
    
    $stmt = $pdo->prepare("UPDATE tambons SET name = ?, icon = ? WHERE id = ?");
    if ($stmt->execute([$name, $icon, $id])) {
        jsonResponse('success', 'แก้ไขข้อมูลเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error updating tambon');
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        jsonResponse('error', 'Missing Tambon ID');
    }
    
    // Check if tambon is in use
    $stmt = $pdo->prepare("SELECT count(*) FROM users WHERE tambon = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
            jsonResponse('error', 'ไม่สามารถลบได้ เนื่องจากมีผู้ดูแลที่ใช้กลุ่มเรียนนี้อยู่');
    }
    
    $stmt = $pdo->prepare("SELECT count(*) FROM records WHERE tambon = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        jsonResponse('error', 'ไม่สามารถลบได้ เนื่องจากมีบันทึกการสอนของกลุ่มเรียนนี้อยู่');
    }
    
    $stmt = $pdo->prepare("DELETE FROM tambons WHERE id = ?");
    if ($stmt->execute([$id])) {
        jsonResponse('success', 'ลบกลุ่มเรียนเรียบร้อยแล้ว');
    } else {
        jsonResponse('error', 'Error deleting tambon');
    }
}

jsonResponse('error', 'Invalid Method');
