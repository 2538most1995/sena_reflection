<?php
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET is public for printing, but we can secure it if we want.
// For now, only authenticated users (district or tambon) can read settings.
$user = checkAuth();

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    if ($user['role'] === 'tambon') {
        $hasScopedSig1Name = hasUserScopedSetting($settings, $user['id'], 'sig1_name')
            || hasTambonScopedSetting($settings, $user['tambon'], 'sig1_name');
        $settings = applyTambonScopedOverrides($settings, $user['tambon'], getSig1SettingKeys());
        $settings = applyUserScopedOverrides($settings, $user['id'], getSig1SettingKeys());
        if (!$hasScopedSig1Name) {
            $settings['sig1_name'] = $user['name'];
        }
    }

    jsonResponse('success', 'fetch settings', stripScopedSettings($settings));
}

if ($method === 'POST' || $method === 'PUT') {
    $data = getJsonInput();
    if (!is_array($data)) {
        jsonResponse('error', 'Invalid payload');
    }

    if ($user['role'] === 'tambon') {
        $allowedSig1Keys = array_flip(getSig1SettingKeys());
        $scopedData = [];

        foreach ($data as $k => $v) {
            if (isset($allowedSig1Keys[$k])) {
                $scopedData[userScopedSettingKey($user['id'], $k)] = $v;
            }
        }

        if (empty($scopedData)) {
            jsonResponse('error', 'ไม่มีข้อมูลที่อนุญาตให้บันทึก');
        }

        $data = $scopedData;
    } else if ($user['role'] !== 'district') {
        jsonResponse('error', 'Unauthorized access');
    }

    foreach ($data as $key => $value) {
        $key = (string) $key;
        if ($key === 'app_logo_path') {
            if ($user['role'] !== 'district' || normalizeUploadPath($value) !== (string) $value) {
                jsonResponse('error', 'ไม่อนุญาตให้แก้ไขเส้นทางโลโก้โดยตรง');
            }
        }
        if (mb_strlen($key) > 100 || mb_strlen((string) $value) > 10000) {
            jsonResponse('error', 'ข้อมูลการตั้งค่ามีขนาดเกินกำหนด');
        }
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($data as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }
        $pdo->commit();
        jsonResponse('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse('error', 'Error saving settings');
    }
}

jsonResponse('error', 'Invalid Method');
