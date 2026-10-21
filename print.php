<?php
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $message = htmlspecialchars((string) ($error['message'] ?? 'Unknown error'), ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars(basename((string) ($error['file'] ?? '')), ENT_QUOTES, 'UTF-8');
    $line = (int) ($error['line'] ?? 0);

    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>PDF Preview Error</title>
        <style>body{font-family:sans-serif;padding:28px;line-height:1.6;color:#172554}.box{max-width:860px;border:1px solid #fecaca;background:#fff5f5;padding:18px;border-radius:8px}.hint{color:#64748b}</style>
    </head><body><div class="box"><h2>เปิดหน้า PDF preview ไม่สำเร็จ</h2><p>' . $message . '</p><p class="hint">ไฟล์: ' . $file . ' บรรทัด: ' . $line . '</p></div></body></html>';
});

require_once __DIR__ . '/config.php';


$id = $_GET['id'] ?? null;
$accessToken = $_GET['access_token'] ?? '';
$shouldDownload = isset($_GET['download']) && $_GET['download'] == 1;
$isSettingsPreview = isset($_GET['settings_preview']) && $_GET['settings_preview'] == 1;
$isRecordDocument = isset($_GET['record_document']) && $_GET['record_document'] == 1;
$isRecordDocumentEditor = isset($_GET['record_document_editor']) && $_GET['record_document_editor'] == 1;
$isLayoutEditorPreview = $isSettingsPreview || $isRecordDocumentEditor;
$isHtmlPreview = isset($_GET['html_preview']) && $_GET['html_preview'] == 1;
$isClientPdfExport = isset($_GET['pdf_export']) && $_GET['pdf_export'] == 1;
$hideAttendance = isset($_GET['hide_attendance']) && $_GET['hide_attendance'] == 1;
$settingsPreviewPayload = [];
$recordDocumentPayload = [];
$recordSnapshot = isset($GLOBALS['SENA_RECORD_SNAPSHOT']) && is_array($GLOBALS['SENA_RECORD_SNAPSHOT'])
    ? $GLOBALS['SENA_RECORD_SNAPSHOT']
    : [];
$hasRecordSnapshot = !empty($recordSnapshot) && is_array($recordSnapshot['record'] ?? null);

if ($isSettingsPreview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($GLOBALS['SENA_SETTINGS_PREVIEW_PAYLOAD']) && is_array($GLOBALS['SENA_SETTINGS_PREVIEW_PAYLOAD'])) {
        $settingsPreviewPayload = $GLOBALS['SENA_SETTINGS_PREVIEW_PAYLOAD'];
    } else {
        $decodedPayload = json_decode(file_get_contents('php://input'), true);
        if (is_array($decodedPayload)) {
            $settingsPreviewPayload = $decodedPayload;
        }
    }
}

if ($isRecordDocument && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($GLOBALS['SENA_RECORD_DOCUMENT_PAYLOAD']) && is_array($GLOBALS['SENA_RECORD_DOCUMENT_PAYLOAD'])) {
        $recordDocumentPayload = $GLOBALS['SENA_RECORD_DOCUMENT_PAYLOAD'];
    } else {
        $decodedPayload = json_decode(file_get_contents('php://input'), true);
        if (is_array($decodedPayload)) {
            $recordDocumentPayload = $decodedPayload;
        }
    }
}

$configuredPrintOverride = trim((string) (getenv('SENA_PRINT_AUTH_OVERRIDE') ?: ''));
if ($configuredPrintOverride !== '' && isset($_GET['auth_override']) && hash_equals($configuredPrintOverride, (string) $_GET['auth_override'])) {
    $user = ['role' => 'district', 'tambon' => ''];
} else {
    $user = getAuthenticatedUserFromToken($accessToken, 'print') ?: getAuthenticatedUserOrNull();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized');
    }
}

if (!$id && $hasRecordSnapshot) {
    $id = (string) ($recordSnapshot['record']['id'] ?? 'snapshot-preview');
}

if (!$id && !$isSettingsPreview) {
    http_response_code(400);
    exit('ไม่พบรหัสบันทึก');
}

if ($hasRecordSnapshot) {
    $record = $recordSnapshot['record'];
    $images = array_values(array_filter(
        is_array($recordSnapshot['images'] ?? null) ? $recordSnapshot['images'] : [],
        static fn ($image) => is_array($image) && isset($image['file_path'])
    ));
    $attendanceRows = array_values(array_filter(
        is_array($recordSnapshot['attendance'] ?? null) ? $recordSnapshot['attendance'] : [],
        static fn ($row) => is_array($row) && !empty($row['present'])
    ));

    if (($user['role'] ?? '') !== 'district' && (string) ($record['tambon'] ?? '') !== (string) ($user['tambon'] ?? '')) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้');
    }
} elseif ($isSettingsPreview && !$id) {
    $record = [
        'id' => 'settings-preview',
        'tambon' => $user['tambon'] ?? '',
        'tambon_name' => 'ตัวอย่างกลุ่มเรียน',
        'teacher_name' => $user['name'] ?? 'ชื่อผู้บันทึก',
        'week' => '10',
        'date' => '2026-04-09',
        'level' => 'มัธยมศึกษาตอนต้น',
        'semester' => '1',
        'year' => '2569',
        'subject' => 'ทักษะการเรียนรู้',
        'subject_code' => 'ทช11001',
        'class_group_code' => '201',
        'class_name' => 'ม.ต้น กลุ่ม 1',
        'total' => '20',
        'present' => '18',
        'result' => 'ตัวอย่างผลการจัดการเรียนการสอน',
        'problem' => 'ตัวอย่างสภาพปัญหาและอุปสรรค',
        'suggestion' => 'ตัวอย่างข้อเสนอแนะและแนวทางแก้ไขปัญหา',
        'supervisor_comment' => '',
    ];
    $images = array_map(function ($index) {
        return ['file_path' => '__settings_preview_image_' . $index . '__'];
    }, range(1, 4));
    $attendanceRows = [
        ['student_id' => '65010001', 'full_name' => 'นาย กิตติพงษ์ ใจดี', 'present' => 1, 'learning_method' => 'พบกลุ่ม'],
        ['student_id' => '65010002', 'full_name' => 'นางสาว พิมพ์ชนก แสงทอง', 'present' => 1, 'learning_method' => 'ออนไลน์'],
        ['student_id' => '65010003', 'full_name' => 'นาย ธนภัทร สุขใจ', 'present' => 1, 'learning_method' => 'พบกลุ่ม'],
        ['student_id' => '65010004', 'full_name' => 'นางสาว ณัฐณิชา บุญมี', 'present' => 1, 'learning_method' => 'พบกลุ่ม'],
    ];
} else {
    $recordSelects = ['r.*'];
    $recordJoins = [];

    if (printTableColumnExists($pdo, 'records', 'tambon') && printTableColumnExists($pdo, 'tambons', 'id') && printTableColumnExists($pdo, 'tambons', 'name')) {
        $recordSelects[] = 't.name as tambon_name';
        $recordJoins[] = 'LEFT JOIN tambons t ON r.tambon COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci';
    } else {
        $recordSelects[] = "'' as tambon_name";
    }

    if (printTableColumnExists($pdo, 'records', 'created_by') && printTableColumnExists($pdo, 'users', 'id') && printTableColumnExists($pdo, 'users', 'name')) {
        $recordSelects[] = 'u.name as teacher_name';
        $recordJoins[] = 'LEFT JOIN users u ON r.created_by COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci';
    } else {
        $recordSelects[] = "'' as teacher_name";
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $recordSelects) . "
        FROM records r
        " . implode("\n        ", $recordJoins) . "
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch();

    if (!$record) {
        http_response_code(404);
        exit('ไม่พบข้อมูลบันทึก');
    }

    if ($user['role'] !== 'district' && $record['tambon'] !== $user['tambon']) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้');
    }

    $images = [];
    if (printTableColumnExists($pdo, 'record_images', 'file_path') && printTableColumnExists($pdo, 'record_images', 'record_id')) {
        $imgStmt = $pdo->prepare("SELECT id, file_path FROM record_images WHERE record_id = ? ORDER BY id ASC LIMIT 4");
        $imgStmt->execute([$id]);
        $images = $imgStmt->fetchAll();
    }

    $attendanceRows = [];
    if (
        printTableColumnExists($pdo, 'record_attendance', 'record_id') &&
        printTableColumnExists($pdo, 'record_attendance', 'student_id') &&
        printTableColumnExists($pdo, 'record_attendance', 'full_name') &&
        printTableColumnExists($pdo, 'record_attendance', 'present')
    ) {
        $attendanceSelects = ['student_id', 'full_name', 'present'];
        $attendanceSelects[] = printTableColumnExists($pdo, 'record_attendance', 'learning_method')
            ? 'learning_method'
            : "'' AS learning_method";
        $attendanceStmt = $pdo->prepare("
            SELECT " . implode(', ', $attendanceSelects) . "
            FROM record_attendance
            WHERE record_id COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
              AND present = 1
            ORDER BY id ASC
        ");
        $attendanceStmt->execute([$id]);
        $attendanceRows = $attendanceStmt->fetchAll();
    }
}

$settings = [];
if ($hasRecordSnapshot) {
    $settings = array_map(
        static fn ($value) => is_scalar($value) || $value === null ? (string) ($value ?? '') : $value,
        is_array($recordSnapshot['settings'] ?? null) ? $recordSnapshot['settings'] : []
    );
    $hasScopedSig1Name = trim((string) ($settings['sig1_name'] ?? '')) !== '';
} else {
    try {
        $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $setStmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        $settings = [];
    }

    $hasScopedSig1Name = hasTambonScopedSetting($settings, $record['tambon'] ?? '', 'sig1_name');
    $settings = applyTambonScopedOverrides($settings, $record['tambon'] ?? '', getSig1SettingKeys());
    if (($user['role'] ?? '') === 'tambon') {
        $hasScopedSig1Name = hasUserScopedSetting($settings, $user['id'] ?? '', 'sig1_name') || $hasScopedSig1Name;
        $settings = applyUserScopedOverrides($settings, $user['id'] ?? '', getSig1SettingKeys());
    }
}

if ($hasRecordSnapshot) {
    $snapshotContent = is_array($recordSnapshot['content'] ?? null) ? $recordSnapshot['content'] : [];
    $snapshotContentKeys = [
        'record_subject' => 'subject',
        'record_subject_code' => 'subject_code',
        'record_class_group_code' => 'class_group_code',
        'record_class_name' => 'class_name',
        'record_result' => 'result',
        'record_problem' => 'problem',
        'record_suggestion' => 'suggestion',
        'record_supervisor_comment' => 'supervisor_comment',
    ];
    foreach ($snapshotContentKeys as $payloadKey => $recordKey) {
        if (array_key_exists($payloadKey, $snapshotContent)) {
            $record[$recordKey] = (string) $snapshotContent[$payloadKey];
        }
    }
}

if ($isRecordDocument && !empty($recordDocumentPayload)) {
    $documentSettings = is_array($recordDocumentPayload['settings'] ?? null)
        ? $recordDocumentPayload['settings']
        : [];
    if (array_key_exists('sig1_name', $documentSettings)) {
        $hasScopedSig1Name = true;
    }
    foreach ($documentSettings as $key => $value) {
        if (
            in_array($key, ['paper_size', 'paper_orientation'], true)
            || preg_match('/^sig[123]_/', (string) $key)
            || preg_match('/^record_section[123]_title$/', (string) $key)
            || in_array($key, [
                'pdf_font_size_pt', 'pdf_line_spacing',
                'record_paragraph_spacing_px', 'record_text_indent_px', 'record_text_align',
                'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px',
                'show_image_page', 'show_attendance_page', 'image_layout_mode',
                'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
                'image_layout_by_id',
            ], true)
            || preg_match('/^image_[1-4]_(width_px|height_px|offset_x|offset_y|x_px|y_px|object_fit|rotation_deg|lock_aspect|crop_x|crop_y)$/', (string) $key)
        ) {
            $settings[$key] = (string) $value;
        }
    }

    $documentContent = is_array($recordDocumentPayload['content'] ?? null)
        ? $recordDocumentPayload['content']
        : [];
    $recordDocumentKeys = [
        'record_subject' => 'subject',
        'record_subject_code' => 'subject_code',
        'record_class_group_code' => 'class_group_code',
        'record_class_name' => 'class_name',
        'record_result' => 'result',
        'record_problem' => 'problem',
        'record_suggestion' => 'suggestion',
        'record_supervisor_comment' => 'supervisor_comment',
    ];
    foreach ($recordDocumentKeys as $payloadKey => $recordKey) {
        if (array_key_exists($payloadKey, $documentContent)) {
            $record[$recordKey] = (string) $documentContent[$payloadKey];
        }
    }
}

if ($isSettingsPreview && !empty($settingsPreviewPayload)) {
    foreach ($settingsPreviewPayload as $key => $value) {
        if (
            preg_match('/^sig[123]_/', (string) $key)
            || preg_match('/^record_section[123]_title$/', (string) $key)
            || in_array($key, [
                'font_size_offset', 'pdf_font_size_pt', 'pdf_line_spacing',
                'record_paragraph_spacing_px', 'record_text_indent_px', 'record_text_align', 'pdf_dot_size',
                'signature_font_size_pt', 'attendance_font_size_pt',
                'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px',
                'show_image_page', 'show_attendance_page', 'image_layout_mode',
                'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
                'image_layout_by_id'
            ], true)
            || preg_match('/^image_[1-4]_(width_px|height_px|offset_x|offset_y|x_px|y_px|object_fit|rotation_deg|lock_aspect|crop_x|crop_y)$/', (string) $key)
        ) {
            $settings[$key] = $value;
        }
    }

    $recordPreviewKeys = [
        'record_result' => 'result',
        'record_problem' => 'problem',
        'record_suggestion' => 'suggestion',
        'record_supervisor_comment' => 'supervisor_comment',
    ];
    foreach ($recordPreviewKeys as $payloadKey => $recordKey) {
        if (array_key_exists($payloadKey, $settingsPreviewPayload)) {
            $record[$recordKey] = (string) $settingsPreviewPayload[$payloadKey];
        }
    }
}

// Stable image layouts follow the image id rather than its current list index.
// Positional keys remain populated for old records and the legacy renderer.
$imageLayoutById = [];
if (isset($settings['image_layout_by_id']) && is_string($settings['image_layout_by_id'])) {
    $decodedImageLayout = json_decode($settings['image_layout_by_id'], true);
    if (is_array($decodedImageLayout)) {
        $imageLayoutById = $decodedImageLayout;
    }
}
$imageLayoutParts = ['width_px', 'height_px', 'x_px', 'y_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'];
foreach (array_values($images) as $imageIndex => $image) {
    $imageId = (string) ($image['id'] ?? 'legacy-' . ($imageIndex + 1));
    $stableLayout = is_array($imageLayoutById[$imageId] ?? null) ? $imageLayoutById[$imageId] : [];
    foreach ($imageLayoutParts as $layoutPart) {
        if (array_key_exists($layoutPart, $stableLayout)) {
            $settings['image_' . ($imageIndex + 1) . '_' . $layoutPart] = (string) $stableLayout[$layoutPart];
        }
    }
}

$showImagePage = ($settings['show_image_page'] ?? '1') === '1';
$showAttendancePage = ($settings['show_attendance_page'] ?? '1') === '1';

$sig1_name = $settings['sig1_name'] ?? '';
if (!$hasScopedSig1Name) {
    $sig1_name = $record['teacher_name'] ?? $sig1_name;
}
$sig1_pos = $settings['sig1_pos'] ?? 'ครูผู้สอน';
$sig1_align = $settings['sig1_align'] ?? 'flex-end';
$sig1_sign_prefix = normalizeSignatureLineLabel($settings['sig1_sign_prefix'] ?? 'ลงชื่อ');
$sig1_sign_suffix = $settings['sig1_sign_suffix'] ?? 'ผู้บันทึก';
$sig1_dot_width = (int) ($settings['sig1_dot_width'] ?? 180);
$sig1_mt = (int) ($settings['sig1_mt'] ?? 48);
$sig1_offset = (int) ($settings['sig1_offset'] ?? 0);
$sig1_offset_y = (int) ($settings['sig1_offset_y'] ?? 0);
$sig1_line_offset_x = (int) ($settings['sig1_line_offset_x'] ?? 0);
$sig1_line_offset_y = (int) ($settings['sig1_line_offset_y'] ?? 0);
$sig1_name_offset_x = (int) ($settings['sig1_name_offset_x'] ?? 0);
$sig1_name_offset_y = (int) ($settings['sig1_name_offset_y'] ?? 0);
$sig1_pos_offset_x = (int) ($settings['sig1_pos_offset_x'] ?? 0);
$sig1_pos_offset_y = (int) ($settings['sig1_pos_offset_y'] ?? 0);
$sig1_sign_prefix_offset_x = (int) ($settings['sig1_sign_prefix_offset_x'] ?? 0);
$sig1_sign_prefix_offset_y = (int) ($settings['sig1_sign_prefix_offset_y'] ?? 0);
$sig1_abs_x = isset($settings['sig1_abs_x']) && $settings['sig1_abs_x'] !== '' ? (int) $settings['sig1_abs_x'] : null;
$sig1_abs_y = isset($settings['sig1_abs_y']) && $settings['sig1_abs_y'] !== '' ? (int) $settings['sig1_abs_y'] : null;
$sig1_abs_w = isset($settings['sig1_abs_w']) && $settings['sig1_abs_w'] !== '' ? (int) $settings['sig1_abs_w'] : null;
$sig1_abs_h = isset($settings['sig1_abs_h']) && $settings['sig1_abs_h'] !== '' ? (int) $settings['sig1_abs_h'] : null;

$sig2_title = $settings['sig2_title'] ?? 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ';
$sig2_sign_label = $settings['sig2_sign_label'] ?? '(ลงชื่อ)';
$sig2_name = $settings['sig2_name'] ?? '';
$sig2_pos = $settings['sig2_pos'] ?? 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ';
$sig2_align = $settings['sig2_align'] ?? 'flex-end';
$sig2_dot_width = (int) ($settings['sig2_dot_width'] ?? 180);
$sig2_mt = (int) ($settings['sig2_mt'] ?? 40);
$sig2_offset = (int) ($settings['sig2_offset'] ?? 0);
$sig2_offset_y = (int) ($settings['sig2_offset_y'] ?? 0);
$sig2_line_offset_x = (int) ($settings['sig2_line_offset_x'] ?? 0);
$sig2_line_offset_y = (int) ($settings['sig2_line_offset_y'] ?? 0);
$sig2_name_offset_x = (int) ($settings['sig2_name_offset_x'] ?? 0);
$sig2_name_offset_y = (int) ($settings['sig2_name_offset_y'] ?? 0);
$sig2_pos_offset_x = (int) ($settings['sig2_pos_offset_x'] ?? 0);
$sig2_pos_offset_y = (int) ($settings['sig2_pos_offset_y'] ?? 0);
$sig2_block_offset_x = (int) ($settings['sig2_block_offset_x'] ?? 0);
$sig2_block_offset_y = (int) ($settings['sig2_block_offset_y'] ?? 0);
$sig2_title_offset_x = (int) ($settings['sig2_title_offset_x'] ?? 0);
$sig2_title_offset_y = (int) ($settings['sig2_title_offset_y'] ?? 0);
$sig2_sign_label_offset_x = (int) ($settings['sig2_sign_label_offset_x'] ?? 0);
$sig2_sign_label_offset_y = (int) ($settings['sig2_sign_label_offset_y'] ?? 0);
$sig2_block_abs_x = isset($settings['sig2_block_abs_x']) && $settings['sig2_block_abs_x'] !== '' ? (int) $settings['sig2_block_abs_x'] : null;
$sig2_block_abs_y = isset($settings['sig2_block_abs_y']) && $settings['sig2_block_abs_y'] !== '' ? (int) $settings['sig2_block_abs_y'] : null;
$sig2_block_abs_w = isset($settings['sig2_block_abs_w']) && $settings['sig2_block_abs_w'] !== '' ? (int) $settings['sig2_block_abs_w'] : null;
$sig2_block_abs_h = isset($settings['sig2_block_abs_h']) && $settings['sig2_block_abs_h'] !== '' ? (int) $settings['sig2_block_abs_h'] : null;
$sig2_signature_abs_x = isset($settings['sig2_signature_abs_x']) && $settings['sig2_signature_abs_x'] !== '' ? (int) $settings['sig2_signature_abs_x'] : null;
$sig2_signature_abs_y = isset($settings['sig2_signature_abs_y']) && $settings['sig2_signature_abs_y'] !== '' ? (int) $settings['sig2_signature_abs_y'] : null;
$sig2_signature_abs_w = isset($settings['sig2_signature_abs_w']) && $settings['sig2_signature_abs_w'] !== '' ? (int) $settings['sig2_signature_abs_w'] : null;
$sig2_signature_abs_h = isset($settings['sig2_signature_abs_h']) && $settings['sig2_signature_abs_h'] !== '' ? (int) $settings['sig2_signature_abs_h'] : null;
$sig2_abs_x = isset($settings['sig2_abs_x']) && $settings['sig2_abs_x'] !== '' ? (int) $settings['sig2_abs_x'] : null;
$sig2_abs_y = isset($settings['sig2_abs_y']) && $settings['sig2_abs_y'] !== '' ? (int) $settings['sig2_abs_y'] : null;
$sig2_abs_w = isset($settings['sig2_abs_w']) && $settings['sig2_abs_w'] !== '' ? (int) $settings['sig2_abs_w'] : null;
$sig2_abs_h = isset($settings['sig2_abs_h']) && $settings['sig2_abs_h'] !== '' ? (int) $settings['sig2_abs_h'] : null;
$sig2_title_abs_x = isset($settings['sig2_title_abs_x']) && $settings['sig2_title_abs_x'] !== '' ? (int) $settings['sig2_title_abs_x'] : null;
$sig2_title_abs_y = isset($settings['sig2_title_abs_y']) && $settings['sig2_title_abs_y'] !== '' ? (int) $settings['sig2_title_abs_y'] : null;
$sig2_title_abs_w = isset($settings['sig2_title_abs_w']) && $settings['sig2_title_abs_w'] !== '' ? (int) $settings['sig2_title_abs_w'] : null;
$sig2_title_abs_h = isset($settings['sig2_title_abs_h']) && $settings['sig2_title_abs_h'] !== '' ? (int) $settings['sig2_title_abs_h'] : null;

$sig3_title = $settings['sig3_title'] ?? 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา';
$sig3_sign_label = $settings['sig3_sign_label'] ?? '(ลงชื่อ)';
$sig3_name = $settings['sig3_name'] ?? 'นางมุจรินทร์ ชมวิชา';
$sig3_pos = $settings['sig3_pos'] ?? 'ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา';
$sig3_align = $settings['sig3_align'] ?? 'center';
$sig3_dot_width = (int) ($settings['sig3_dot_width'] ?? 200);
$sig3_mt = (int) ($settings['sig3_mt'] ?? 38);
$sig3_offset = (int) ($settings['sig3_offset'] ?? 0);
$sig3_offset_y = (int) ($settings['sig3_offset_y'] ?? 0);
$sig3_line_offset_x = (int) ($settings['sig3_line_offset_x'] ?? 0);
$sig3_line_offset_y = (int) ($settings['sig3_line_offset_y'] ?? 0);
$sig3_name_offset_x = (int) ($settings['sig3_name_offset_x'] ?? 0);
$sig3_name_offset_y = (int) ($settings['sig3_name_offset_y'] ?? 0);
$sig3_pos_offset_x = (int) ($settings['sig3_pos_offset_x'] ?? 0);
$sig3_pos_offset_y = (int) ($settings['sig3_pos_offset_y'] ?? 0);
$sig3_block_offset_x = (int) ($settings['sig3_block_offset_x'] ?? 0);
$sig3_block_offset_y = (int) ($settings['sig3_block_offset_y'] ?? 0);
$sig3_title_offset_x = (int) ($settings['sig3_title_offset_x'] ?? 0);
$sig3_title_offset_y = (int) ($settings['sig3_title_offset_y'] ?? 0);
$sig3_sign_label_offset_x = (int) ($settings['sig3_sign_label_offset_x'] ?? 0);
$sig3_sign_label_offset_y = (int) ($settings['sig3_sign_label_offset_y'] ?? 0);
$sig3_block_abs_x = isset($settings['sig3_block_abs_x']) && $settings['sig3_block_abs_x'] !== '' ? (int) $settings['sig3_block_abs_x'] : null;
$sig3_block_abs_y = isset($settings['sig3_block_abs_y']) && $settings['sig3_block_abs_y'] !== '' ? (int) $settings['sig3_block_abs_y'] : null;
$sig3_block_abs_w = isset($settings['sig3_block_abs_w']) && $settings['sig3_block_abs_w'] !== '' ? (int) $settings['sig3_block_abs_w'] : null;
$sig3_block_abs_h = isset($settings['sig3_block_abs_h']) && $settings['sig3_block_abs_h'] !== '' ? (int) $settings['sig3_block_abs_h'] : null;
$sig3_abs_x = isset($settings['sig3_abs_x']) && $settings['sig3_abs_x'] !== '' ? (int) $settings['sig3_abs_x'] : null;
$sig3_abs_y = isset($settings['sig3_abs_y']) && $settings['sig3_abs_y'] !== '' ? (int) $settings['sig3_abs_y'] : null;
$sig3_abs_w = isset($settings['sig3_abs_w']) && $settings['sig3_abs_w'] !== '' ? (int) $settings['sig3_abs_w'] : null;
$sig3_abs_h = isset($settings['sig3_abs_h']) && $settings['sig3_abs_h'] !== '' ? (int) $settings['sig3_abs_h'] : null;
$sig3_title_abs_x = isset($settings['sig3_title_abs_x']) && $settings['sig3_title_abs_x'] !== '' ? (int) $settings['sig3_title_abs_x'] : null;
$sig3_title_abs_y = isset($settings['sig3_title_abs_y']) && $settings['sig3_title_abs_y'] !== '' ? (int) $settings['sig3_title_abs_y'] : null;
$sig3_title_abs_w = isset($settings['sig3_title_abs_w']) && $settings['sig3_title_abs_w'] !== '' ? (int) $settings['sig3_title_abs_w'] : null;
$sig3_title_abs_h = isset($settings['sig3_title_abs_h']) && $settings['sig3_title_abs_h'] !== '' ? (int) $settings['sig3_title_abs_h'] : null;

$recordSection1Title = $settings['record_section1_title'] ?? '๑. ผลการจัดการเรียนการสอน';
$recordSection2Title = $settings['record_section2_title'] ?? '๒. สภาพปัญหาและอุปสรรค';
$recordSection3Title = $settings['record_section3_title'] ?? '๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา';

$timestamp = strtotime($record['date']);
$day = date('j', $timestamp);
$thaiMonths = [
    1 => 'มกราคม',
    2 => 'กุมภาพันธ์',
    3 => 'มีนาคม',
    4 => 'เมษายน',
    5 => 'พฤษภาคม',
    6 => 'มิถุนายน',
    7 => 'กรกฎาคม',
    8 => 'สิงหาคม',
    9 => 'กันยายน',
    10 => 'ตุลาคม',
    11 => 'พฤศจิกายน',
    12 => 'ธันวาคม',
];
$month = $thaiMonths[(int) date('n', $timestamp)];
$year = (int) date('Y', $timestamp) + 543;
$absent = max(0, (int) ($record['total'] ?? 0) - (int) ($record['present'] ?? 0));

$paperOrientation = ($settings['paper_orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
define('PDF_PAGE_WIDTH', $paperOrientation === 'landscape' ? 1123 : 794);
define('PDF_PAGE_HEIGHT', $paperOrientation === 'landscape' ? 794 : 1123);
const PDF_PAGE_PADDING_TOP = 57;
const PDF_PAGE_PADDING_RIGHT = 76;
const PDF_PAGE_PADDING_BOTTOM = 38;
const PDF_PAGE_PADDING_LEFT = 94;
define('PDF_CONTENT_WIDTH', PDF_PAGE_WIDTH - PDF_PAGE_PADDING_LEFT - PDF_PAGE_PADDING_RIGHT);
const PDF_SIGNATURE_WIDTH = 430;
const PDF_FLOW_SIG1_ALIGN = 'flex-end';
const PDF_FLOW_SIG2_ALIGN = 'flex-end';
const PDF_FLOW_SIG3_ALIGN = 'center';
const PDF_FLOW_SIG1_OFFSET_X = 0;
const PDF_FLOW_SIG2_OFFSET_X = 0;
const PDF_FLOW_SIG3_OFFSET_X = 0;
const PDF_FLOW_SIG1_DOT_WIDTH = 180;
const PDF_FLOW_SIG2_DOT_WIDTH = 180;
const PDF_FLOW_SIG3_DOT_WIDTH = 200;
const PDF_FLOW_SIG1_MARGIN_TOP = 48;
const PDF_FLOW_SIG2_SECTION_MARGIN_TOP = 18;
const PDF_FLOW_SIG2_SIGNATURE_MARGIN_TOP = 40;
const PDF_FLOW_SIG3_SECTION_MARGIN_TOP = 24;
const PDF_FLOW_SIG3_SIGNATURE_MARGIN_TOP = 38;

function printTableColumnExists(PDO $pdo, $table, $column)
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([(string) $column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}


function buildPrintableDocumentHtml($contentHtml, $filename)
{
    $safeTitle = htmlspecialchars((string) $filename, ENT_QUOTES, 'UTF-8');
    $baseHref = htmlspecialchars(getRequestBaseHref(), ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="th"><head><meta charset="utf-8"><base href="' . $baseHref . '"><title>' . $safeTitle . '</title></head><body>' . $contentHtml . '</body></html>';
}

function getRequestBaseHref()
{
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = $forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $scriptDir = $scriptDir === '/' || $scriptDir === '.' ? '' : '/' . trim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir . '/';
}

function pathToBrowserAssetUrl($relativePath)
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return '';
    }

    if (preg_match('/^(?:https?:|data:|blob:)/i', $relativePath)) {
        return $relativePath;
    }

    return getRequestBaseHref() . ltrim($relativePath, '/');
}

function pathToLocalFileUrl($path)
{
    $path = str_replace('\\', '/', (string) $path);
    if ($path === '') {
        return '';
    }

    return 'file://' . str_replace('%2F', '/', rawurlencode($path));
}

function outputPrintableHtmlPreview($contentHtml, $filename)
{
    global $recordTextAlign, $pdfPageMarginTopPx, $pdfPageMarginRightPx, $pdfPageMarginBottomPx, $pdfPageMarginLeftPx, $isClientPdfExport, $isLayoutEditorPreview;

    if (ob_get_length()) {
        ob_clean();
    }

    $safeTitle = htmlspecialchars((string) $filename, ENT_QUOTES, 'UTF-8');
    $baseHref = htmlspecialchars(getRequestBaseHref(), ENT_QUOTES, 'UTF-8');
    $documentHeadStyles = '';
    $contentHtml = (string) $contentHtml;
    while (preg_match('/^\s*<style\b[^>]*>/i', $contentHtml, $styleOpenMatch, PREG_OFFSET_CAPTURE)) {
        $styleStart = (int) $styleOpenMatch[0][1];
        $styleOpenEnd = $styleStart + strlen($styleOpenMatch[0][0]);
        $styleEnd = stripos($contentHtml, '</style>', $styleOpenEnd);
        if ($styleEnd === false) {
            break;
        }
        $styleEnd += strlen('</style>');
        $documentHeadStyles .= substr($contentHtml, $styleStart, $styleEnd - $styleStart) . "\n";
        $contentHtml = substr($contentHtml, 0, $styleStart) . substr($contentHtml, $styleEnd);
    }
    $previewPaddingTop = isset($pdfPageMarginTopPx) ? (int) $pdfPageMarginTopPx : PDF_PAGE_PADDING_TOP;
    $previewPaddingRight = isset($pdfPageMarginRightPx) ? (int) $pdfPageMarginRightPx : PDF_PAGE_PADDING_RIGHT;
    $previewPaddingBottom = isset($pdfPageMarginBottomPx) ? (int) $pdfPageMarginBottomPx : PDF_PAGE_PADDING_BOTTOM;
    $previewPaddingLeft = isset($pdfPageMarginLeftPx) ? (int) $pdfPageMarginLeftPx : PDF_PAGE_PADDING_LEFT;
    $a4GuideCss = !$isClientPdfExport ? '
                .page::after {
                    content: "";
                    position: absolute;
                    inset: 0;
                    pointer-events: none;
                    z-index: 999;
                    background-image: repeating-linear-gradient(
                        to bottom,
                        transparent 0,
                        transparent calc(' . PDF_PAGE_HEIGHT . 'px - 2px),
                        rgba(194, 65, 12, 0.85) calc(' . PDF_PAGE_HEIGHT . 'px - 2px),
                        rgba(194, 65, 12, 0.85) ' . PDF_PAGE_HEIGHT . 'px
                    );
                }
                .page::before {
                    content: "เส้นแบ่งหน้า A4";
                    position: absolute;
                    right: 10px;
                    top: calc(' . PDF_PAGE_HEIGHT . 'px - 20px);
                    z-index: 1000;
                    font: 12px/1.2 Arial, sans-serif;
                    color: rgba(154, 52, 18, 0.86);
                    background: rgba(255, 247, 237, 0.92);
                    border: 1px solid rgba(251, 146, 60, 0.65);
                    border-radius: 4px;
                    padding: 2px 6px;
                    pointer-events: none;
                }
' : '';
    $responsiveDirectPreviewCss = !$isClientPdfExport ? '
                html.html-preview-direct {
                    --html-preview-scale: 1;
                    background: #e8eef7;
                }
                html.html-preview-direct body {
                    width: 100%;
                    min-width: 0;
                    overflow: auto;
                    padding: clamp(10px, 3vw, 24px);
                    box-sizing: border-box;
                    background:
                        linear-gradient(45deg, rgba(22,54,98,0.04) 25%, transparent 25%),
                        linear-gradient(-45deg, rgba(22,54,98,0.04) 25%, transparent 25%),
                        #e8eef7;
                    background-size: 18px 18px;
                    -webkit-overflow-scrolling: touch;
                }
                html.html-preview-direct .page {
                    zoom: var(--html-preview-scale);
                    transform-origin: top center;
                }
                @media (max-width: 860px) {
                    html.html-preview-direct body {
                        padding: 8px;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                    }
                    html.html-preview-direct .page {
                        margin-bottom: 18px !important;
                        margin-left: auto !important;
                        margin-right: auto !important;
                    }
                }
                @media (max-width: 480px) {
                    html.html-preview-direct body {
                        padding: 4px;
                    }
                }
' : '';
    $responsiveDirectPreviewScript = !$isClientPdfExport ? '
        <script>
            (function () {
                if (window.self !== window.top) {
                    return;
                }
                document.documentElement.classList.add("html-preview-direct");
                function fitHtmlPreview() {
                    var pageWidth = ' . PDF_PAGE_WIDTH . ';
                    var sideSpace = window.innerWidth <= 480 ? 8 : (window.innerWidth <= 640 ? 16 : 48);
                    var availableWidth = Math.max(0, window.innerWidth - sideSpace);
                    var scale = Math.min(1, Math.max(0.28, availableWidth / pageWidth));
                    document.documentElement.style.setProperty("--html-preview-scale", scale.toFixed(4));
                }
                fitHtmlPreview();
                window.addEventListener("resize", fitHtmlPreview, { passive: true });
                window.addEventListener("orientationchange", function () {
                    window.setTimeout(fitHtmlPreview, 200);
                }, { passive: true });
            }());
        </script>
' : '';
    $clientPdfExportCss = $isClientPdfExport ? '
                html, body {
                    background: #ffffff !important;
                    overflow: visible !important;
                    -webkit-font-smoothing: antialiased;
                    text-rendering: optimizeLegibility;
                }
                .page {
                    border: none !important;
                    box-shadow: none !important;
                    margin: 0 !important;
                }
                .dotted-text-content p.official-para,
                .dotted-text-content p,
                .official-line {
                    text-align: ' . $recordTextAlign . ' !important;
                    text-justify: inter-character !important;
                    -webkit-text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ' !important;
                    text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ' !important;
                    letter-spacing: 0 !important;
                    word-spacing: normal !important;
                    font-kerning: none !important;
                }
                .dotted-text-content,
                .dotted-text-content p.official-para,
                .dotted-text-content p,
                .row-table td,
                .sig-line-table td,
                .sig-box,
                .section-title,
                h2 {
                    font-synthesis: none !important;
                }
' : '';
    $previewCss = '
        <style>
            @media screen {
                html {
                    margin: 0;
                    padding: 0;
                    background: transparent;
                }
                body {
                    margin: 0;
                    padding: 0;
                    background: transparent;
                    width: max-content;
                    min-width: ' . PDF_PAGE_WIDTH . 'px;
                    overflow: hidden;
                }
                .page-break {
                    break-before: auto !important;
                    page-break-before: auto !important;
                }
                .page {
                    width: ' . PDF_PAGE_WIDTH . 'px !important;
                    min-height: ' . PDF_PAGE_HEIGHT . 'px !important;
                    padding: ' . $previewPaddingTop . 'px ' . $previewPaddingRight . 'px ' . $previewPaddingBottom . 'px ' . $previewPaddingLeft . 'px !important;
                    box-sizing: border-box !important;
                    margin: 0 auto 24px !important;
                    background: #fff !important;
                    position: relative !important;
                    overflow: visible !important;
                    border: none !important;
                    outline: 1px solid rgba(148, 163, 184, 0.46);
                    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12) !important;
                }
                .page + .page,
                .page--screen-continuation {
                    margin-top: 34px !important;
                }
' . $responsiveDirectPreviewCss . '
' . $a4GuideCss . '
                .preprint-html-hidden {
                    display: none !important;
                }
                .preprint-live-draggable {
                    outline: 2px dashed rgba(47,103,177,0.40);
                    outline-offset: 5px;
                    cursor: grab;
                    touch-action: none;
                    user-select: none;
                }
                .preprint-live-draggable:hover,
                .preprint-live-draggable.is-selected,
                .preprint-live-draggable.is-dragging {
                    outline-color: rgba(47,103,177,0.95);
                    background: rgba(239,246,255,0.42);
                    box-shadow: 0 0 0 4px rgba(47,103,177,0.10);
                }
                .preprint-live-draggable.is-selected { z-index: 10; }
                .preprint-live-draggable.is-dragging {
                    z-index: 20;
                    cursor: grabbing;
                    outline-color: rgba(194,122,18,0.95);
                    background: rgba(255,244,223,0.72);
                }
                .preprint-move-handle {
                    position:absolute; right:0; top:-24px; z-index:30;
                    font:12px/20px sans-serif; color:#1a3a6b; background:#eff6ff;
                    border:1px solid #93b4dc; border-radius:4px; padding:0 6px;
                    cursor:grab; touch-action:none; user-select:none;
                }
                .pdf-preview-block--flow-signature { position:relative; }
                .preprint-live-editable {
                    cursor: text;
                    border-radius: 4px;
                    white-space: break-spaces;
                    user-select: text;
                    -webkit-user-select: text;
                    pointer-events: auto;
                }
                .sig-box-line .preprint-live-editable,
                .sig-box-line-tight .preprint-live-editable {
                    white-space: break-spaces;
                }
                .preprint-live-editable:hover {
                    background: rgba(41,82,163,0.08);
                }
                .preprint-live-editable[data-preprint-dblclick="1"] {
                    cursor: text;
                }
                .preprint-live-editable[data-preprint-dblclick="1"].is-editing {
                    cursor: text;
                }
                .preprint-live-editable.is-editing::after { content: "\200B"; }
                .preprint-live-editable:focus,
                .preprint-live-editable.is-editing {
                    outline: none;
                    background: rgba(232,160,32,0.14);
                    box-shadow: 0 0 0 2px rgba(232,160,32,0.24);
                }
                .image-shell-two,
                .image-shell-four {
                    position: relative;
                }
                .image-free-canvas {
                    overflow: visible;
                    outline: 1px dashed rgba(100,116,139,0.36);
                    outline-offset: 3px;
                    background-image:
                        linear-gradient(rgba(148,163,184,0.08) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(148,163,184,0.08) 1px, transparent 1px);
                    background-size: 24px 24px;
                }
                .image-free-canvas::before {
                    content: "";
                    position: absolute;
                    z-index: 4;
                    inset: 0;
                    border: 1px dashed rgba(34,197,94,0.55);
                    pointer-events: none;
                }
                .image-free-item.is-outside-safe-area {
                    outline-color: #d97706;
                    box-shadow: 0 0 0 4px rgba(217,119,6,0.14);
                }
                .preprint-image-resize-handle {
                    position: absolute;
                    z-index: 30;
                    box-sizing: border-box;
                    border: 2px solid #fff;
                    background: #1a3a6b;
                    box-shadow: 0 4px 12px rgba(26,58,107,0.34);
                    padding: 0;
                    touch-action: none;
                }
                .preprint-image-resize-handle[data-layout-resize="n"] { top:-8px; left:50%; cursor:ns-resize; transform:translateX(-50%); }
                .preprint-image-resize-handle[data-layout-resize="s"] { bottom:-8px; left:50%; cursor:ns-resize; transform:translateX(-50%); }
                .preprint-image-resize-handle[data-layout-resize="e"] { right:-8px; top:50%; cursor:ew-resize; transform:translateY(-50%); }
                .preprint-image-resize-handle[data-layout-resize="w"] { left:-8px; top:50%; cursor:ew-resize; transform:translateY(-50%); }
                .preprint-image-resize-handle[data-layout-resize="n"],
                .preprint-image-resize-handle[data-layout-resize="s"] { width:32px; height:14px; border-radius:7px; }
                .preprint-image-resize-handle[data-layout-resize="e"],
                .preprint-image-resize-handle[data-layout-resize="w"] { width:14px; height:32px; border-radius:7px; }
                .preprint-image-resize-handle[data-layout-resize="nw"] { left:-9px; top:-9px; cursor:nwse-resize; }
                .preprint-image-resize-handle[data-layout-resize="ne"] { right:-9px; top:-9px; cursor:nesw-resize; }
                .preprint-image-resize-handle[data-layout-resize="sw"] { left:-9px; bottom:-9px; cursor:nesw-resize; }
                .preprint-image-resize-handle[data-layout-resize="se"] { right:-9px; bottom:-9px; cursor:nwse-resize; }
                .preprint-image-resize-handle[data-layout-resize="nw"],
                .preprint-image-resize-handle[data-layout-resize="ne"],
                .preprint-image-resize-handle[data-layout-resize="sw"],
                .preprint-image-resize-handle[data-layout-resize="se"] { width:20px; height:20px; border-radius:50%; }
                .settings-preview-image-placeholder {
                    display: flex;
                    width: 100%;
                    height: 100%;
                    box-sizing: border-box;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    color: #234a46;
                    text-align: center;
                    background:
                        linear-gradient(135deg, rgba(111,194,184,0.24), rgba(35,74,70,0.08)),
                        repeating-linear-gradient(45deg, rgba(35,74,70,0.05) 0, rgba(35,74,70,0.05) 10px, transparent 10px, transparent 20px);
                    border: 1px solid rgba(35,74,70,0.28);
                }
                .settings-preview-image-placeholder strong {
                    font: 700 22px/1.2 Arial, sans-serif;
                }
                .settings-preview-image-placeholder span {
                    max-width: 180px;
                    font: 13px/1.45 Arial, sans-serif;
                }
' . $clientPdfExportCss . '
            }
        </style>
    ';

    $settingsEditorScript = '';
    if ($isLayoutEditorPreview && !$isClientPdfExport) {
        $settingsEditorScript = <<<'HTML'
        <script>
            (function () {
                var active = null;
                var selected = null;
                var frame = null;
                var pendingPointer = null;
                var number = function (value) { return Number(value || 0) || 0; };
                var clamp = function (value, min, max) { return Math.max(min, Math.min(max, Math.round(value))); };
                var safeInset = 0;
                var imageItems = function () { return Array.from(document.querySelectorAll('.image-free-item[data-layout-kind="image"]')); };
                var warningIds = function () {
                    return imageItems().filter(function (item) {
                        var parent = item.parentElement;
                        var x = number(item.dataset.layoutX);
                        var y = number(item.dataset.layoutY);
                        var width = number(item.dataset.layoutWidth);
                        var height = number(item.dataset.layoutHeight);
                        var warning = Boolean(parent) && (x < safeInset || y < safeInset || x + width > parent.clientWidth - safeInset || y + height > parent.clientHeight - safeInset);
                        item.classList.toggle('is-outside-safe-area', warning);
                        return warning;
                    }).map(function (item) { return item.dataset.layoutImageId || item.dataset.layoutLabel; });
                };
                var notifyGeometry = function () {
                    var pages = Array.from(document.querySelectorAll('.page')).filter(function (page) { return page.getBoundingClientRect().height > 0; });
                    if (!pages.length) return;
                    window.parent.postMessage({
                        source: 'sena-pdf-geometry',
                        pageWidth: pages.reduce(function (width, page) { return Math.max(width, page.getBoundingClientRect().width); }, 0),
                        documentHeight: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
                        pageCount: pages.length,
                        pages: pages.map(function (page) { var rect = page.getBoundingClientRect(); return { width: Math.round(rect.width), height: Math.round(rect.height), top: Math.round(rect.top) }; }),
                        safeWarnings: warningIds()
                    }, '*');
                };
                var notify = function (item, changes) {
                    window.parent.postMessage({
                        source: 'sena-pdf-layout',
                        changes: changes || {},
                        safeWarnings: warningIds(),
                        target: { kind: item.dataset.layoutKind || 'move', label: item.dataset.layoutLabel || 'องค์ประกอบ', imageId: item.dataset.layoutImageId || null }
                    }, '*');
                    notifyGeometry();
                };
                var readText = function (item) {
                    return (item.textContent || '').replace(/\u00a0/g, ' ').replace(/\r\n?|[\u2028\u2029]/g, '\n');
                };
                var renderText = function (item, text) {
                    item.dataset.preprintText = text;
                    item.replaceChildren();
                    if (item.classList.contains('dotted-text-content')) {
                        text.split(/(\[break\]|\[page-break\]|\*\*\*)/i).forEach(function (part) {
                            var block = document.createElement('p');
                            if (/^(\[break\]|\[page-break\]|\*\*\*)$/i.test(part)) {
                                block = document.createElement('div');
                                block.className = 'manual-page-break';
                                block.style.cssText = 'height:0;margin:0;padding:0;break-before:page;page-break-before:always';
                            } else {
                                block.className = 'official-para official-para-first';
                                block.textContent = part + (!part || part.endsWith('\n') ? '\u00a0' : '');
                            }
                            item.appendChild(block);
                        });
                    } else if (item.dataset.preprintAllowBr) {
                        text.split('\n').forEach(function (line) {
                            var block = document.createElement('div');
                            block.style.cssText = 'margin:0;padding:0'; block.textContent = line || '\u00a0';
                            item.appendChild(block);
                        });
                    } else item.textContent = text;
                };
                var notifyText = function (item) {
                    var text = readText(item);
                    var changes = {};
                    changes[item.dataset.preprintSetting] = text;
                    window.parent.postMessage({ source: 'sena-pdf-layout', textChanges: changes, target: { kind: 'text', label: 'ข้อความในเอกสาร' } }, '*');
                    notifyGeometry();
                };
                var select = function (item) {
                    if (selected && selected !== item) selected.classList.remove('is-selected');
                    selected = item;
                    item.classList.add('is-selected');
                    notify(item, {});
                };
                var applyImageMedia = function (item) {
                    var image = item.querySelector('img');
                    if (!image) return;
                    var rotation = number(item.dataset.layoutRotation) % 360;
                    var quarterTurn = rotation === 90 || rotation === 270;
                    image.style.width = quarterTurn ? number(item.dataset.layoutHeight) + 'px' : '100%';
                    image.style.height = quarterTurn ? number(item.dataset.layoutWidth) + 'px' : '100%';
                    image.style.objectFit = item.dataset.layoutFit || 'contain';
                    image.style.objectPosition = (item.dataset.layoutCropX || '50') + '% ' + (item.dataset.layoutCropY || '50') + '%';
                    image.style.transform = 'translate(-50%,-50%) rotate(' + rotation + 'deg)';
                };
                var resizeValues = function (state, clientX, clientY, shiftKey) {
                    var mode = state.resizeMode;
                    var dx = (clientX - state.startClientX) / state.scaleX;
                    var dy = (clientY - state.startClientY) / state.scaleY;
                    var left = state.startX;
                    var top = state.startY;
                    var width = state.startWidth;
                    var height = state.startHeight;
                    var right = state.startX + state.startWidth;
                    var bottom = state.startY + state.startHeight;
                    if (mode.indexOf('e') !== -1) width = state.startWidth + dx;
                    if (mode.indexOf('s') !== -1) height = state.startHeight + dy;
                    if (mode.indexOf('w') !== -1) { width = state.startWidth - dx; left = right - width; }
                    if (mode.indexOf('n') !== -1) { height = state.startHeight - dy; top = bottom - height; }
                    var keepAspect = state.lockAspect !== Boolean(shiftKey);
                    var ratio = state.startWidth / Math.max(1, state.startHeight);
                    if (keepAspect) {
                        if (mode === 'e' || mode === 'w') {
                            height = width / ratio;
                            top = state.startY + (state.startHeight - height) / 2;
                        } else if (mode === 'n' || mode === 's') {
                            width = height * ratio;
                            left = state.startX + (state.startWidth - width) / 2;
                        } else if (Math.abs(width - state.startWidth) >= Math.abs((height - state.startHeight) * ratio)) {
                            height = width / ratio;
                            if (mode.indexOf('n') !== -1) top = bottom - height;
                        } else {
                            width = height * ratio;
                            if (mode.indexOf('w') !== -1) left = right - width;
                        }
                    }
                    var parentWidth = state.parentWidth || 1200;
                    var parentHeight = state.parentHeight || 1100;
                    if (keepAspect) {
                        if (width < 60) { width = 60; height = width / ratio; }
                        if (height < 60) { height = 60; width = height * ratio; }
                        var scale = Math.min(1, 1000 / width, 950 / height, parentWidth / width, parentHeight / height);
                        width *= scale;
                        height *= scale;
                        if (mode.indexOf('w') !== -1) left = right - width;
                        else if (mode === 'n' || mode === 's') left = state.startX + (state.startWidth - width) / 2;
                        if (mode.indexOf('n') !== -1) top = bottom - height;
                        else if (mode === 'e' || mode === 'w') top = state.startY + (state.startHeight - height) / 2;
                        left = clamp(left, 0, Math.max(0, parentWidth - width));
                        top = clamp(top, 0, Math.max(0, parentHeight - height));
                    } else {
                        left = clamp(left, 0, Math.max(0, parentWidth - 60));
                        top = clamp(top, 0, Math.max(0, parentHeight - 60));
                        width = clamp(width, 60, Math.min(1000, parentWidth - left));
                        height = clamp(height, 60, Math.min(950, parentHeight - top));
                    }
                    return { x: Math.round(left), y: Math.round(top), width: Math.round(width), height: Math.round(height) };
                };
                var renderPointer = function (clientX, clientY, shiftKey) {
                    if (!active) return;
                    var dx = (clientX - active.startClientX) / active.scaleX;
                    var dy = (clientY - active.startClientY) / active.scaleY;
                    if (active.resizeMode) {
                        var box = resizeValues(active, clientX, clientY, shiftKey);
                        active.item.style.left = box.x + 'px'; active.item.style.top = box.y + 'px';
                        active.item.style.width = box.width + 'px'; active.item.style.height = box.height + 'px';
                        active.item.dataset.layoutX = String(box.x); active.item.dataset.layoutY = String(box.y);
                        active.item.dataset.layoutWidth = String(box.width); active.item.dataset.layoutHeight = String(box.height);
                        applyImageMedia(active.item);
                    } else if (active.absolute) {
                        var x = clamp(active.startX + dx, 0, Math.max(0, active.parentWidth - active.startWidth));
                        var y = clamp(active.startY + dy, 0, Math.max(0, active.parentHeight - active.startHeight));
                        active.item.style.left = x + 'px'; active.item.style.top = y + 'px';
                        active.item.dataset.layoutX = String(x); active.item.dataset.layoutY = String(y);
                    } else {
                        var offsetX = clamp(active.startX + dx, -400, 400);
                        var offsetY = clamp(active.startY + dy, -400, 400);
                        active.item.dataset.layoutX = String(offsetX); active.item.dataset.layoutY = String(offsetY);
                        active.item.style.transform = 'translateX(' + offsetX + 'px)';
                        active.item.style.marginTop = offsetY + 'px';
                    }
                    warningIds();
                };
                document.addEventListener('pointerdown', function (event) {
                    if (active || event.button !== 0 || event.target.closest('[data-preprint-setting]')) return;
                    var item = event.target.closest('[data-layout-kind]');
                    if (!item) return;
                    event.preventDefault();
                    select(item);
                    var parent = item.parentElement;
                    var parentRect = parent ? parent.getBoundingClientRect() : null;
                    var resizeHandle = event.target.closest('[data-layout-resize]');
                    active = {
                        item: item, pointerId: event.pointerId,
                        scaleX: parent && parent.offsetWidth ? parentRect.width / parent.offsetWidth : 1,
                        scaleY: parent && parent.offsetHeight ? parentRect.height / parent.offsetHeight : 1,
                        resizeMode: resizeHandle ? resizeHandle.dataset.layoutResize : '',
                        absolute: item.dataset.layoutPosition === 'absolute',
                        lockAspect: item.dataset.layoutLockAspect === '1',
                        startClientX: event.clientX, startClientY: event.clientY,
                        startX: number(item.dataset.layoutX), startY: number(item.dataset.layoutY),
                        startWidth: number(item.dataset.layoutWidth), startHeight: number(item.dataset.layoutHeight),
                        parentWidth: parent ? parent.clientWidth : 1200, parentHeight: parent ? parent.clientHeight : 1100
                    };
                    item.classList.add('is-dragging');
                    if (item.setPointerCapture) item.setPointerCapture(event.pointerId);
                });
                document.addEventListener('pointermove', function (event) {
                    if (!active || active.pointerId !== event.pointerId) return;
                    pendingPointer = { x: event.clientX, y: event.clientY, shift: event.shiftKey };
                    if (frame !== null) return;
                    frame = window.requestAnimationFrame(function () {
                        frame = null;
                        if (pendingPointer) renderPointer(pendingPointer.x, pendingPointer.y, pendingPointer.shift);
                        pendingPointer = null;
                    });
                });
                var finish = function (event) {
                    if (!active || active.pointerId !== event.pointerId) return;
                    if (frame !== null) window.cancelAnimationFrame(frame);
                    // Cancelled/capture-lost events may report (0,0); keep the last real drag position.
                    if (event.type === 'pointerup') renderPointer(event.clientX, event.clientY, event.shiftKey);
                    else if (pendingPointer) renderPointer(pendingPointer.x, pendingPointer.y, pendingPointer.shift);
                    frame = null; pendingPointer = null;
                    var changes = {};
                    if (active.item.dataset.layoutXKey && active.item.dataset.layoutYKey) {
                        changes[active.item.dataset.layoutXKey] = active.item.dataset.layoutX;
                        changes[active.item.dataset.layoutYKey] = active.item.dataset.layoutY;
                    }
                    if (active.resizeMode) {
                        changes[active.item.dataset.layoutWidthKey] = active.item.dataset.layoutWidth;
                        changes[active.item.dataset.layoutHeightKey] = active.item.dataset.layoutHeight;
                    }
                    active.item.classList.remove('is-dragging');
                    notify(active.item, changes);
                    active = null;
                };
                document.addEventListener('pointerup', finish);
                document.addEventListener('pointercancel', finish);
                document.addEventListener('lostpointercapture', finish);
                document.querySelectorAll('[data-preprint-setting]').forEach(function (item) {
                    item.setAttribute('title', 'คลิกเพื่อแก้ข้อความ · Enter เพิ่มบรรทัด · Tab ย่อหน้า · Esc ยกเลิก');
                    item.addEventListener('click', function (event) {
                        event.stopPropagation();
                        if (item.isContentEditable) return;
                        item.dataset.originalText = item.dataset.preprintText !== undefined ? item.dataset.preprintText : (item.innerText || '');
                        item.textContent = item.dataset.originalText;
                        item.contentEditable = 'plaintext-only'; item.classList.add('is-editing'); item.focus();
                        if (document.caretRangeFromPoint) {
                            var caret = document.caretRangeFromPoint(event.clientX, event.clientY);
                            if (caret && item.contains(caret.startContainer)) {
                                var selection = window.getSelection();
                                selection.removeAllRanges(); selection.addRange(caret);
                            }
                        }
                    });
                    item.addEventListener('input', function () { notifyText(item); });
                    item.addEventListener('paste', function (event) {
                        if (!item.isContentEditable || !event.clipboardData) return;
                        event.preventDefault();
                        var pasted = event.clipboardData.getData('text/plain').replace(/\r\n?|\n/g, item.dataset.preprintAllowBr ? '\u2028' : ' ');
                        document.execCommand('insertText', false, pasted);
                    });
                    item.addEventListener('blur', function () {
                        if (!item.isContentEditable) return;
                        notifyText(item);
                        var text = readText(item);
                        item.contentEditable = 'false'; item.classList.remove('is-editing');
                        renderText(item, text);
                    });
                    item.addEventListener('keydown', function (event) {
                        if (!item.isContentEditable || event.isComposing) return;
                        event.stopPropagation();
                        if (event.key === 'Escape') { event.preventDefault(); item.textContent = item.dataset.originalText || ''; notifyText(item); item.blur(); return; }
                        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey || !item.dataset.preprintAllowBr)) { event.preventDefault(); item.blur(); return; }
                        if (event.key === 'Tab' || event.key === 'Enter') {
                            event.preventDefault();
                            // A literal line separator keeps native undo without Chrome inserting block divs.
                            document.execCommand('insertText', false, event.key === 'Tab' ? '\t' : '\u2028');
                        }
                    });
                });
                document.addEventListener('keydown', function (event) {
                    if (event.target.closest('[data-preprint-setting], input, textarea, select')) return;
                    var item = event.target.closest('[data-layout-kind]');
                    if (!item || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
                    event.preventDefault(); select(item);
                    var distance = event.shiftKey ? 10 : 1;
                    var x = number(item.dataset.layoutX); var y = number(item.dataset.layoutY);
                    if (event.key === 'ArrowLeft') x -= distance; if (event.key === 'ArrowRight') x += distance;
                    if (event.key === 'ArrowUp') y -= distance; if (event.key === 'ArrowDown') y += distance;
                    var absolute = item.dataset.layoutPosition === 'absolute'; var parent = item.parentElement;
                    x = absolute ? clamp(x, 0, parent ? Math.max(0, parent.clientWidth - number(item.dataset.layoutWidth)) : 1200) : clamp(x, -400, 400);
                    y = absolute ? clamp(y, 0, parent ? Math.max(0, parent.clientHeight - number(item.dataset.layoutHeight)) : 1100) : clamp(y, -400, 400);
                    item.dataset.layoutX = String(x); item.dataset.layoutY = String(y);
                    if (absolute) { item.style.left = x + 'px'; item.style.top = y + 'px'; }
                    else { item.style.transform = 'translateX(' + x + 'px)'; item.style.marginTop = y + 'px'; }
                    var changes = {}; changes[item.dataset.layoutXKey] = String(x); changes[item.dataset.layoutYKey] = String(y); notify(item, changes);
                });
                window.addEventListener('message', function (event) {
                    if (event.source !== window.parent) return;
                    if (event.data?.source === 'sena-document-print-request') {
                        if (active) finish({ pointerId: active.pointerId, type: 'capture' });
                        if (document.activeElement && document.activeElement.isContentEditable) document.activeElement.blur();
                        // Export the displayed DOM, preserving every image's current geometry.
                        var clone = document.documentElement.cloneNode(true);
                        clone.querySelectorAll('script, .preprint-image-resize-handle, .preprint-image-resize-corner, .preprint-move-handle').forEach(function (item) { item.remove(); });
                        clone.querySelectorAll('[contenteditable]').forEach(function (item) { item.removeAttribute('contenteditable'); });
                        clone.querySelectorAll('.is-selected, .is-editing, .is-dragging').forEach(function (item) { item.classList.remove('is-selected', 'is-editing', 'is-dragging'); });
                        var cleanStyle = document.createElement('style');
                        cleanStyle.textContent = '.preprint-live-draggable,.preprint-live-editable{outline:none!important;box-shadow:none!important;background-color:transparent!important;cursor:default!important}.image-free-canvas{background-image:none!important;outline:none!important}.image-free-canvas::before,.page::before,.page::after{display:none!important}';
                        clone.querySelector('head').appendChild(cleanStyle);
                        window.parent.postMessage({ source: 'sena-document-print-result', requestId: event.data.requestId, html: '<!doctype html>' + clone.outerHTML }, '*');
                        return;
                    }
                    if (event.data?.source === 'sena-pdf-layout-select') {
                        var target = Array.from(document.querySelectorAll('[data-layout-kind]')).find(function (item) {
                            return event.data.imageId ? item.dataset.layoutImageId === String(event.data.imageId) : item.dataset.layoutLabel === event.data.label;
                        });
                        if (target) select(target);
                        return;
                    }
                    if (event.data?.source !== 'sena-pdf-layout-patch' || !event.data.changes) return;
                    var changes = event.data.changes;
                    document.querySelectorAll('[data-layout-kind]').forEach(function (item) {
                        if (active && active.item === item) return;
                        var pairs = [
                            ['layoutXKey', 'layoutX', 'left'], ['layoutYKey', 'layoutY', 'top'],
                            ['layoutWidthKey', 'layoutWidth', 'width'], ['layoutHeightKey', 'layoutHeight', 'height']
                        ];
                        pairs.forEach(function (pair) {
                            var key = item.dataset[pair[0]];
                            if (key && Object.prototype.hasOwnProperty.call(changes, key)) {
                                item.dataset[pair[1]] = String(changes[key]);
                                if (item.dataset.layoutPosition === 'absolute' || pair[2] === 'width' || pair[2] === 'height') item.style[pair[2]] = String(changes[key]) + 'px';
                            }
                        });
                        if (item.dataset.layoutFitKey && Object.prototype.hasOwnProperty.call(changes, item.dataset.layoutFitKey)) item.dataset.layoutFit = String(changes[item.dataset.layoutFitKey]);
                        if (item.dataset.layoutRotationKey && Object.prototype.hasOwnProperty.call(changes, item.dataset.layoutRotationKey)) item.dataset.layoutRotation = String(changes[item.dataset.layoutRotationKey]);
                        if (item.dataset.layoutLockKey && Object.prototype.hasOwnProperty.call(changes, item.dataset.layoutLockKey)) item.dataset.layoutLockAspect = String(changes[item.dataset.layoutLockKey]);
                        if (item.dataset.layoutCropXKey && Object.prototype.hasOwnProperty.call(changes, item.dataset.layoutCropXKey)) item.dataset.layoutCropX = String(changes[item.dataset.layoutCropXKey]);
                        if (item.dataset.layoutCropYKey && Object.prototype.hasOwnProperty.call(changes, item.dataset.layoutCropYKey)) item.dataset.layoutCropY = String(changes[item.dataset.layoutCropYKey]);
                        if (item.dataset.layoutWidthKey) applyImageMedia(item);
                        else { item.style.transform = 'translateX(' + number(item.dataset.layoutX) + 'px)'; item.style.marginTop = number(item.dataset.layoutY) + 'px'; }
                    });
                    warningIds(); notifyGeometry();
                });
                imageItems().forEach(applyImageMedia);
                var ready = function () { warningIds(); notifyGeometry(); };
                window.addEventListener('load', ready);
                if (document.fonts && document.fonts.ready) document.fonts.ready.then(ready);
                if (window.ResizeObserver) new ResizeObserver(ready).observe(document.body);
                window.requestAnimationFrame(ready);
            }());
        </script>
HTML;
    }

    $htmlClass = $isClientPdfExport ? ' class="client-pdf-export"' : '';
    $html = '<!doctype html><html lang="th"' . $htmlClass . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="' . $baseHref . '"><title>' . $safeTitle . '</title>' . $documentHeadStyles . $previewCss . $responsiveDirectPreviewScript . '</head><body>' . $contentHtml . $settingsEditorScript . '</body></html>';
    if (defined('SENA_PRINT_CAPTURE_HTML') && SENA_PRINT_CAPTURE_HTML) {
        return $html;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}


function officialFirstLineIndentPx()
{
    global $settings;
    return normalizePrintSettingInt($settings, 'record_text_indent_px', 42, 0, 160);
}

function resolveLocalPath($relativePath)
{
    // Draft files remain private. Only a server-created CLI snapshot may
    // reference a file inside this exact storage subtree.
    if (PHP_SAPI === 'cli' && defined('SENA_PRINT_CAPTURE_HTML') && SENA_PRINT_CAPTURE_HTML && isset($GLOBALS['SENA_RECORD_SNAPSHOT'])) {
        require_once __DIR__ . '/laravel/app/Services/DraftImagePath.php';
        $candidate = \App\Services\DraftImagePath::resolve((string) $relativePath, $GLOBALS['SENA_RECORD_SNAPSHOT']['images'] ?? []);
        if ($candidate) {
            return $candidate;
        }
    }

    require_once __DIR__ . '/laravel/app/Services/RecordImageAssets.php';
    $relativePath = \App\Services\RecordImageAssets::normalizeStoredPath((string) $relativePath);
    if ($relativePath === null) {
        return null;
    }

    $uploadPath = getUploadFilePath($relativePath);
    if ($uploadPath && is_file($uploadPath)) {
        return str_replace('\\', '/', realpath($uploadPath));
    }

    // Laravel stores new uploads under laravel/storage/app/public while the
    // legacy renderer historically read only the project-level uploads folder.
    // Supporting both paths keeps old records and newly created records printable.
    $normalized = $relativePath;
    if (strpos($normalized, 'storage/') === 0) {
        $laravelStoragePath = __DIR__ . '/laravel/storage/app/public/' . substr($normalized, 8);
        if (is_file($laravelStoragePath)) {
            return str_replace('\\', '/', realpath($laravelStoragePath));
        }
    }

    $absolutePath = realpath(__DIR__ . '/' . ltrim((string) $relativePath, '/'));
    return $absolutePath ? str_replace('\\', '/', $absolutePath) : null;
}

/**
 * Use browser-accessible URLs in shared HTML previews.
 */
function resolveImageSrc($relativePath, $localPath, $isHtmlPreview)
{
    if (defined('SENA_PRINT_CAPTURE_HTML') && SENA_PRINT_CAPTURE_HTML && $localPath) {
        if (defined('SENA_PRINT_CAPTURE_LOCAL_ASSETS') && SENA_PRINT_CAPTURE_LOCAL_ASSETS) {
            return pathToLocalFileUrl($localPath);
        }

        $dataUrl = imageFileDataUrl($localPath);
        if ($dataUrl !== '') {
            return $dataUrl;
        }
    }

    return pathToBrowserAssetUrl($relativePath);
}

function pxToMm($px)
{
    return ((float) $px) * 25.4 / 96;
}

function pdfDottedTextMaxWidthMm()
{
    return pxToMm(PDF_CONTENT_WIDTH - officialFirstLineIndentPx() - 18);
}

function ensureDocumentFonts($fontDir)
{
    if (!is_dir($fontDir)) {
        @mkdir($fontDir, 0777, true);
    }

    $fonts = [
        'THSarabunNew.ttf' => 'https://raw.githubusercontent.com/epsilonxe/SIPAFonts/master/THSarabunNew.ttf',
        'THSarabunNew-Bold.ttf' => 'https://raw.githubusercontent.com/epsilonxe/SIPAFonts/master/THSarabunNew%20Bold.ttf',
        'Sarabun-Regular.ttf' => 'https://github.com/googlefonts/sarabun/raw/main/fonts/ttf/Sarabun-Regular.ttf',
        'Sarabun-Bold.ttf' => 'https://github.com/googlefonts/sarabun/raw/main/fonts/ttf/Sarabun-Bold.ttf',
    ];

    foreach ($fonts as $filename => $url) {
        $path = $fontDir . '/' . $filename;
        if (file_exists($path)) {
            continue;
        }

        if (!function_exists('curl_init')) {
            continue;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($code === 200 && $data) {
            file_put_contents($path, $data);
        }
    }
}

function fontFileDataUrl($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $data = file_get_contents($path);
    if ($data === false || $data === '') {
        return '';
    }

    return 'data:font/truetype;charset=utf-8;base64,' . base64_encode($data);
}

function imageFileDataUrl($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $data = file_get_contents($path);
    if ($data === false || $data === '') {
        return '';
    }

    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
            if (is_string($detected) && preg_match('/^image\//', $detected)) {
                $mime = $detected;
            }
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function escapeTextPreserveSpaces($text)
{
    $escaped = htmlspecialchars((string) ($text ?? ''), ENT_QUOTES, 'UTF-8');
    return preg_replace_callback('/ {2,}/', function ($matches) {
        return str_repeat('&nbsp;', strlen($matches[0]) - 1) . ' ';
    }, $escaped);
}

function escapeTextPreserveSpacesAllowBr($text)
{
    return textToHtmlWithLineBreaks($text);
}

function parseManualPageBreaks($html) {
    // Replace [break], [page-break], or *** with a special div that our JS pagination logic will recognize
    $pattern = '/(?:\[break\]|\[page\-break\]|\*\*\*)/i';
    $replacement = '<div class="manual-page-break" style="height: 0; margin: 0; padding: 0; width: 100%; clear: both; page-break-before: always; break-before: page;" data-manual-page-break="true"></div>';
    return preg_replace($pattern, $replacement, $html);
}

/**
 * Convert newlines in text to <br> tags for dotted text areas.
 * Preserves blank lines by inserting a non-breaking space.
 */
function textToHtmlWithLineBreaks($text)
{
    $normalized = str_replace(["\r\n", "\r"], "\n", (string) ($text ?? ''));
    $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $normalized);
    // Escape HTML
    $escaped = htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8');
    // Preserve multiple spaces
    $escaped = preg_replace_callback('/ {2,}/', function ($matches) {
        return str_repeat('&nbsp;', strlen($matches[0]) - 1) . ' ';
    }, $escaped);
    // Split into lines and handle blank lines
    $lines = explode("\n", $escaped);
    $result = [];
    foreach ($lines as $line) {
        // If line is empty, use a non-breaking space so the line takes up vertical space
        $content = $line === '' ? '&nbsp;' : $line;
        // Wrap each explicit line in a div so html2canvas correctly calculates line boxes
        $result[] = '<div style="margin: 0; padding: 0;">' . $content . '</div>';
    }
    return parseManualPageBreaks(implode('', $result));
}

function preprintEditableHtml($settingKey, $html, $allowBr = false, $doubleClick = false)
{
    $settingKey = htmlspecialchars((string) $settingKey, ENT_QUOTES, 'UTF-8');
    $allowBrAttr = $allowBr ? ' data-preprint-allow-br="1"' : '';
    $parsedHtml = parseManualPageBreaks($html);
    $doubleClickAttr = $doubleClick ? ' data-preprint-dblclick="1"' : '';
    $plainHtml = preg_replace('/<\/div>\s*<div[^>]*>/i', "\n", $html);
    $plainText = htmlspecialchars(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $plainHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    return '<span class="preprint-live-editable" data-preprint-text="' . $plainText . '" data-preprint-setting="' . $settingKey . '"' . $allowBrAttr . $doubleClickAttr . '>' . $parsedHtml . '</span>';
}

function normalizePdfFontSizePt($value)
{
    $fontSize = (int) round((float) $value);
    return max(10, min(20, $fontSize ?: 16));
}

function resolvePdfFontSizeOffsetPx(array $settings)
{
    if (isset($settings['pdf_font_size_pt']) && $settings['pdf_font_size_pt'] !== '') {
        $fontSizePt = normalizePdfFontSizePt($settings['pdf_font_size_pt']);
        return ($fontSizePt - 16) * (96 / 72);
    }

    $legacyOffset = (float) ($settings['font_size_offset'] ?? 0);
    return max(-6, min(6, $legacyOffset));
}

function resolvePdfFontSizePt(array $settings)
{
    if (isset($settings['pdf_font_size_pt']) && $settings['pdf_font_size_pt'] !== '') {
        return normalizePdfFontSizePt($settings['pdf_font_size_pt']);
    }

    $legacyOffset = (float) ($settings['font_size_offset'] ?? 0);
    return normalizePdfFontSizePt(16 + ($legacyOffset * 72 / 96));
}

function ptToPx($pt)
{
    return (float) $pt * (96 / 72);
}

function normalizePrintSettingInt(array $settings, $key, $default, $min, $max)
{
    $value = isset($settings[$key]) && $settings[$key] !== '' ? (int) round((float) $settings[$key]) : (int) $default;
    return max((int) $min, min((int) $max, $value));
}

function hasPrintSettingValue(array $settings, $key)
{
    return isset($settings[$key]) && $settings[$key] !== '';
}

function buildInfoRow(array $segments)
{
    $html = '<table class="row-table"><tr>';
    $usedPercent = 0.0;

    foreach ($segments as $segment) {
        $label = $segment['label'] ?? '';
        $value = array_key_exists('value', $segment) ? $segment['value'] : null;
        $width = $segment['width'] ?? null;
        $labelWidth = $segment['label_width'] ?? null;
        $nowrap = !empty($segment['nowrap']);
        $usedPercent += extractPercentWidth($labelWidth);
        $labelClass = 'label-cell' . ($nowrap ? ' nowrap' : '');
        $labelWidthStyle = $labelWidth ? ' style="width:' . htmlspecialchars($labelWidth, ENT_QUOTES, 'UTF-8') . ';"' : '';
        $html .= '<td class="' . $labelClass . '"' . $labelWidthStyle . '>' . escapeTextPreserveSpaces($label) . '</td>';
        if ($value === null) {
            continue;
        }
        $usedPercent += extractPercentWidth($width);
        $valueClass = 'dotted-line' . ($nowrap ? ' nowrap' : '');
        $widthStyle = $width ? ' style="width:' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8') . ';"' : '';
        $html .= '<td class="' . $valueClass . '"' . $widthStyle . '>' . escapeTextPreserveSpaces($value) . '</td>';
    }

    if ($usedPercent > 0 && $usedPercent < 99) {
        $html .= '<td class="row-spacer" style="width:' . max(0, 100 - $usedPercent) . '%;"></td>';
    }

    $html .= '</tr></table>';
    return $html;
}

function extractPercentWidth($width)
{
    $width = trim((string) ($width ?? ''));
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)%$/', $width, $matches)) {
        return 0.0;
    }

    return (float) $matches[1];
}

function buildDottedTextArea($text, $minLines, $maxLines = null, $settingKey = '')
{
    global $pdfLineSpacing;
    $spacing = isset($pdfLineSpacing) ? (int)$pdfLineSpacing : 26;
    $normalized = str_replace(["\r\n", "\r"], "\n", (string) ($text ?? ''));

    // Newlines are line breaks inside one official paragraph, not new indented paragraphs.
    // This keeps the first-line indent only at the very first visual line of the field.
    $editableText = $normalized;
    $normalized = preg_replace('/(?:\[break\]|\[page\-break\]|\*\*\*)/i', "\n[[SENA_PAGE_BREAK]]\n", $normalized);
    $sourceLines = explode("\n", $normalized);

    $contentParts = [];
    $paragraphLines = [];
    $flushParagraph = function () use (&$contentParts, &$paragraphLines) {
        if (empty($paragraphLines)) {
            return;
        }

        $lineHtml = [];
        foreach ($paragraphLines as $line) {
            $lineHtml[] = trim($line) !== '' ? htmlspecialchars($line, ENT_QUOTES, 'UTF-8') : '&nbsp;';
        }
        $contentParts[] = '<p class="official-para official-para-first">' . implode("\n", $lineHtml) . '</p>';
        $paragraphLines = [];
    };

    foreach ($sourceLines as $srcLine) {
        if (trim($srcLine) === '[[SENA_PAGE_BREAK]]') {
            $flushParagraph();
            $contentParts[] = '<div class="manual-page-break" style="height: 0; margin: 0; padding: 0; width: 100%; clear: both; page-break-before: always; break-before: page;" data-manual-page-break="true"></div>';
            continue;
        }
        $paragraphLines[] = $srcLine;
    }
    $flushParagraph();

    if (empty($contentParts)) {
        $contentParts[] = '<p class="official-para official-para-first">&nbsp;</p>';
    }
    $content = implode('', $contentParts);

    // Style: NO min-height so no empty dotted lines remain after text ends
    $style = '';
    if ($maxLines !== null) {
        $maxLinesVal = max(1, (int) $maxLines);
        $maxHeight = $maxLinesVal * $spacing;
        $style .= 'max-height:' . $maxHeight . 'px; overflow:hidden;';
    }

    $contentClass = 'dotted-text-content dotted-text-content--prewrapped';
    $contentAttrs = '';
    if ($settingKey !== '') {
        $contentClass .= ' preprint-live-editable';
        $contentAttrs .= ' data-preprint-setting="' . htmlspecialchars((string) $settingKey, ENT_QUOTES, 'UTF-8') . '"';
        $contentAttrs .= ' data-preprint-text="' . htmlspecialchars($editableText, ENT_QUOTES, 'UTF-8') . '"';
        $contentAttrs .= ' data-preprint-allow-br="1"';
        $contentAttrs .= ' data-preprint-dblclick="1"';
    }

    $areaStyle = $style !== '' ? ' style="' . $style . '"' : '';
    return '<div class="dotted-text-area"' . $areaStyle . '><div class="' . $contentClass . '"' . $contentAttrs . '>' . $content . '</div></div>';
}

function getDottedTextContentLines($text)
{
    $normalized = str_replace(["\r\n", "\r"], "\n", (string) ($text ?? ''));
    $maxWidthMm = pdfDottedTextMaxWidthMm();
    $lines = wrapDottedTextLines($normalized, $maxWidthMm);
    return array_values(array_filter($lines, function ($line) {
        return trim((string) $line) !== '';
    }));
}

function hasDottedTextOverflow($text, $maxLines)
{
    return count(getDottedTextContentLines($text)) > (int) $maxLines;
}

function buildFullTextDetailSection($title, $text)
{
    return '
        <div class="detail-section">
            <div class="section-title">' . escapeTextPreserveSpaces($title) . '</div>
            ' . buildDottedTextArea($text, 1) . '
        </div>
    ';
}

function wrapDottedTextLines($text, $maxWidthMm)
{
    $sourceLines = explode("\n", (string) $text);
    $wrapped = [];

    foreach ($sourceLines as $sourceLine) {
        if (trim($sourceLine) === '') {
            $wrapped[] = '';
            continue;
        }

        $tokens = preg_split('/(\s+)/u', (string) $sourceLine, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $currentLine = '';

        foreach ($tokens as $token) {
            $candidate = $currentLine . $token;

            if ($currentLine === '' && trim($token) === '') {
                continue;
            }

            if (fitsPdfTextLine($candidate, $maxWidthMm)) {
                $currentLine = $candidate;
                continue;
            }

            if ($currentLine !== '') {
                $wrapped[] = rtrim($currentLine);
                $currentLine = ltrim($token);
                if ($currentLine === '') {
                    continue;
                }
            } else {
                $currentLine = ltrim($token);
            }

            if (fitsPdfTextLine($currentLine, $maxWidthMm)) {
                continue;
            }

            foreach (splitPdfTokenByWidth($currentLine, $maxWidthMm) as $partIndex => $part) {
                if ($partIndex === 0) {
                    $currentLine = $part;
                    continue;
                }
                $wrapped[] = rtrim($currentLine);
                $currentLine = $part;
            }
        }

        $wrapped[] = rtrim($currentLine);
    }

    return $wrapped;
}

function splitPdfTokenByWidth($token, $maxWidthMm)
{
    $parts = [];
    $current = '';
    $chars = preg_split('//u', (string) $token, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($chars as $char) {
        $candidate = $current . $char;
        if ($current !== '' && !fitsPdfTextLine($candidate, $maxWidthMm)) {
            $parts[] = $current;
            $current = $char;
            continue;
        }
        $current = $candidate;
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function fitsPdfTextLine($text, $maxWidthMm)
{
    return measurePdfTextWidth($text) <= $maxWidthMm
        && mb_strlen((string) $text, 'UTF-8') <= maxPdfCharsForWidth($maxWidthMm);
}

function maxPdfCharsForWidth($maxWidthMm)
{
    global $pdfFontSizePt;
    $fontScale = isset($pdfFontSizePt) ? max(0.75, ((float) $pdfFontSizePt) / 16) : 1;
    return max(42, (int) floor(((float) $maxWidthMm) / (2.05 * $fontScale)));
}

function measurePdfTextWidth($text)
{
    return 0;
}

function buildSignatureRow($align, $marginTop, $offsetX, $offsetY, $contentHtml, array $options = [])
{
    $align = normalizeSignatureAlign($align);
    $isWide = !empty($options['wide']);
    $isPlaceholder = !empty($options['placeholder']);
    $forceCenter = !empty($options['center_box']);
    $boxClassExtra = trim((string) ($options['box_class'] ?? ''));
    $boxStyleExtra = trim((string) ($options['box_style'] ?? ''));
    $contentWidth = PDF_PAGE_WIDTH - PDF_PAGE_PADDING_LEFT - PDF_PAGE_PADDING_RIGHT;
    $blockWidth = (int) ($options['block_width'] ?? ($isWide ? $contentWidth : 430));
    $baseX = max(0, $contentWidth - $blockWidth);

    if ($forceCenter || $align === 'center') {
        $baseX = (int) round(max(0, $contentWidth - $blockWidth) / 2);
    } elseif ($align === 'flex-start') {
        $baseX = 0;
    }

    $wrapperStyle = 'margin-top:' . ((int) $marginTop + (int) $offsetY) . 'px;';
    $resolvedX = $baseX + (int) $offsetX;
    $leftWidth = max(0, $resolvedX);
    $boxStyle = 'display:block; width:' . $blockWidth . 'px;';
    if ($boxStyleExtra !== '') {
        $boxStyle .= ' ' . $boxStyleExtra;
    }
    $boxClass = 'pdf-preview-block' . ($isWide ? ' pdf-preview-block--wide' : '') . ($isPlaceholder ? ' pdf-placeholder' : '') . ($boxClassExtra !== '' ? ' ' . $boxClassExtra : '');

    return '
        <table class="signature-row" style="' . $wrapperStyle . ' width:' . ($leftWidth + $blockWidth) . 'px;">
            <tr>
                <td style="width:' . $leftWidth . 'px; padding:0;"></td>
                <td class="signature-cell" style="width:' . $blockWidth . 'px; padding:0;">
                    <div class="' . $boxClass . '" style="' . $boxStyle . '">
                        <div class="sig-box">' . $contentHtml . '</div>
                    </div>
                </td>
            </tr>
        </table>
    ';
}

function buildFlowSignatureBlock($align, $marginTop, $contentHtml, $blockWidth = PDF_SIGNATURE_WIDTH, array $options = [])
{
    global $isLayoutEditorPreview;

    $align = normalizeSignatureAlign($align);
    $resolvedWidth = min((int) $blockWidth, PDF_CONTENT_WIDTH);
    $justifyContent = 'flex-end';
    if ($align === 'center') {
        $justifyContent = 'center';
    } elseif ($align === 'flex-start') {
        $justifyContent = 'flex-start';
    }

    $offsetX = (int) ($options['offset_x'] ?? 0);
    $offsetY = (int) ($options['offset_y'] ?? 0);
    $transformStyle = ($offsetX !== 0 || $offsetY !== 0)
        ? ' transform:translateX(' . $offsetX . 'px); margin-top:' . $offsetY . 'px;'
        : '';
    $editorClass = '';
    $editorAttributes = '';
    if ($isLayoutEditorPreview && !empty($options['editor_x']) && !empty($options['editor_y'])) {
        $editorClass = ' preprint-live-draggable';
        $editorLabel = htmlspecialchars((string) ($options['editor_label'] ?? 'องค์ประกอบเอกสาร'), ENT_QUOTES, 'UTF-8');
        $editorAttributes = ' tabindex="0" role="button" aria-label="ลากเพื่อจัดตำแหน่ง ' . $editorLabel . '"'
            . ' data-layout-kind="move"'
            . ' data-layout-label="' . $editorLabel . '"'
            . ' data-layout-x-key="' . htmlspecialchars((string) $options['editor_x'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-layout-y-key="' . htmlspecialchars((string) $options['editor_y'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-layout-x="' . $offsetX . '" data-layout-y="' . $offsetY . '"';
    }

    $moveHandle = $editorClass !== '' ? '<span class="preprint-move-handle" title="ลากเพื่อย้ายลายเซ็น">✥ ย้ายลายเซ็น</span>' : '';

    return '
        <div class="signature-flow-section" style="margin-top:' . (int) $marginTop . 'px; width:100%; display:flex; justify-content:' . $justifyContent . ';">
            <div class="pdf-preview-block pdf-preview-block--flow-signature' . $editorClass . '"' . $editorAttributes . ' style="width:' . $resolvedWidth . 'px;' . $transformStyle . '">' . $moveHandle . '
                <div class="sig-box">' . $contentHtml . '</div>
            </div>
        </div>
    ';
}

function normalizeSignatureAlign($align)
{
    $align = strtolower(trim((string) $align));

    if ($align === 'left') {
        return 'flex-start';
    }

    if ($align === 'right') {
        return 'flex-end';
    }

    if (in_array($align, ['flex-start', 'center', 'flex-end'], true)) {
        return $align;
    }

    return 'flex-end';
}

function normalizeSignatureLineLabel($label)
{
    $label = trim((string) $label);
    if ($label === '' || $label === 'ลงชื่อ') {
        return '(ลงชื่อ)';
    }

    return $label;
}

function buildSignatureLine($prefix, $dotWidth, $suffix = '', array $options = [])
{
    $prefixOffsetX = (int) ($options['prefix_offset_x'] ?? 0);
    $prefixOffsetY = (int) ($options['prefix_offset_y'] ?? 0);

    $prefixCellStyles = [];
    if ($prefixOffsetX > 0) {
        $prefixCellStyles[] = 'padding-left:' . $prefixOffsetX . 'px';
    } elseif ($prefixOffsetX < 0) {
        $prefixCellStyles[] = 'text-indent:' . $prefixOffsetX . 'px';
    }

    if ($prefixOffsetY > 0) {
        $prefixCellStyles[] = 'padding-top:' . $prefixOffsetY . 'px';
    } elseif ($prefixOffsetY < 0) {
        $prefixCellStyles[] = 'padding-bottom:' . abs($prefixOffsetY) . 'px';
    }

    $balanceWithoutSuffix = !empty($options['balance_without_suffix']);
    $balanceWidth = max(42, (int) round(mb_strlen((string) $prefix, 'UTF-8') * 11));
    $html = '<table class="sig-line-table"><tr>';
    
    $prefixCellStyle = $prefixCellStyles ? ' style="' . implode('; ', $prefixCellStyles) . ';"' : '';
    $prefixHtml = escapeTextPreserveSpaces($prefix);
    if (!empty($options['prefix_setting'])) {
        $prefixHtml = preprintEditableHtml($options['prefix_setting'], $prefixHtml);
    }
    $html .= '<td class="sig-line-prefix"' . $prefixCellStyle . '>';
    $html .= $prefixHtml;
    $html .= '</td>';
    
    $html .= '<td class="sig-line-dot" style="width:' . (int) $dotWidth . 'px;">&nbsp;</td>';

    if ($suffix !== '') {
        $suffixHtml = escapeTextPreserveSpaces($suffix);
        if (!empty($options['suffix_setting'])) {
            $suffixHtml = preprintEditableHtml($options['suffix_setting'], $suffixHtml);
        }
        $html .= '<td class="sig-line-suffix">' . $suffixHtml . '</td>';
    } elseif ($balanceWithoutSuffix) {
        $html .= '<td class="sig-line-suffix sig-line-suffix-placeholder" style="width:' . $balanceWidth . 'px;">&nbsp;</td>';
    }

    $html .= '</tr></table>';
    return $html;
}

function buildShiftedSignaturePart($contentHtml, $offsetX, $offsetY, $className = 'sig-box-line-tight')
{
    $style = '';
    if ((int) $offsetX !== 0 || (int) $offsetY !== 0) {
        $style = ' style="margin-left:' . (int) $offsetX . 'px; margin-top:' . (int) $offsetY . 'px;"';
    }

    return '<div class="' . $className . '"' . $style . '>' . $contentHtml . '</div>';
}

function buildTitleBlock($text, $offsetX, $offsetY, array $options = [])
{
    $style = 'margin-left:' . (int) $offsetX . 'px; margin-top:' . (int) $offsetY . 'px;';
    $class = 'pdf-preview-title-block' . (!empty($options['placeholder']) ? ' pdf-placeholder' : '');
    return '
        <div class="' . $class . '" style="' . $style . '">
            <div class="section-title pdf-preview-title-text">' . escapeTextPreserveSpaces($text) . '</div>
        </div>
    ';
}

function buildAbsoluteTitleBlock($text, $x, $y, $width = null)
{
    if ($x === null || $y === null) {
        return '';
    }

    $resolvedX = max(0, (int) $x - PDF_PAGE_PADDING_LEFT);
    $resolvedY = max(0, (int) $y - PDF_PAGE_PADDING_TOP);
    $widthStyle = $width ? ' width:' . (int) $width . 'px;' : '';
    $style = 'left:' . $resolvedX . 'px; top:' . $resolvedY . 'px;' . $widthStyle;

    return '
        <div class="pdf-absolute-block pdf-preview-title-block" style="' . $style . '">
            <div class="section-title pdf-preview-title-text">' . escapeTextPreserveSpaces($text) . '</div>
        </div>
    ';
}

function buildAbsoluteSignatureBlock($contentHtml, $x, $y, $width = null, array $options = [])
{
    if ($x === null || $y === null) {
        return '';
    }

    $isWide = !empty($options['wide']);
    $resolvedX = max(0, (int) $x - PDF_PAGE_PADDING_LEFT);
    $resolvedY = max(0, (int) $y - PDF_PAGE_PADDING_TOP);
    $widthStyle = $width ? ' width:' . (int) $width . 'px;' : '';
    $style = 'left:' . $resolvedX . 'px; top:' . $resolvedY . 'px;' . $widthStyle;
    $boxClass = 'pdf-absolute-block pdf-preview-block' . ($isWide ? ' pdf-preview-block--wide' : '');

    return '
        <div class="' . $boxClass . '" style="' . $style . '">
            <div class="sig-box">' . $contentHtml . '</div>
        </div>
    ';
}

function buildShiftedSectionTitle($text, $offsetX, $offsetY, $settingKey = '')
{
    $style = 'margin-left:' . (int) $offsetX . 'px; margin-top:' . (int) $offsetY . 'px;';
    $titleHtml = escapeTextPreserveSpaces($text);
    if ($settingKey !== '') {
        $titleHtml = preprintEditableHtml($settingKey, $titleHtml);
    }
    return '<div class="section-title" style="' . $style . '">' . $titleHtml . '</div>';
}

function buildShiftedSectionBlock($contentHtml, $offsetX, $offsetY)
{
    $style = 'margin-left:' . (int) $offsetX . 'px; margin-top:' . (int) $offsetY . 'px;';
    return '
        <div class="pdf-section-block" style="' . $style . '">
            ' . $contentHtml . '
        </div>
    ';
}

function buildAttendanceSheetHtml(array $record, array $attendanceRows, $day, $month, $year)
{
    $rowCount = max(25, count($attendanceRows));
    $html = '<div class="page-break"></div>';
    $html .= '<div class="page attendance-page">';
    $html .= '<table class="attendance-title-table"><tr>';
    $html .= '<td class="attendance-title-main">บัญชีรายชื่อผู้เรียน</td>';
    $html .= '<td class="attendance-title-label">ครั้งที่</td>';
    $html .= '<td class="attendance-title-dot">' . escapeTextPreserveSpaces($record['week'] ?? '') . '</td>';
    $html .= '<td class="attendance-title-label">วันที่</td>';
    $html .= '<td class="attendance-title-dot">' . escapeTextPreserveSpaces($day) . '</td>';
    $html .= '<td class="attendance-title-label">เดือน</td>';
    $html .= '<td class="attendance-title-dot attendance-title-dot-month">' . escapeTextPreserveSpaces($month) . '</td>';
    $html .= '<td class="attendance-title-label">พ.ศ.</td>';
    $html .= '<td class="attendance-title-dot attendance-title-dot-year">' . escapeTextPreserveSpaces($year) . '</td>';
    $html .= '</tr></table>';
    $html .= '<table class="attendance-table">';
    $html .= '<thead><tr>';
    $html .= '<th class="attendance-col-no">ลำดับ<br>ที่</th>';
    $html .= '<th class="attendance-col-id">รหัสนักศึกษา</th>';
    $html .= '<th class="attendance-col-name">ชื่อ - ชื่อสกุล</th>';
    $html .= '<th class="attendance-col-sign">ลายมือชื่อ</th>';
    $html .= '<th class="attendance-col-note">หมายเหตุ</th>';
    $html .= '</tr></thead><tbody>';

    for ($i = 0; $i < $rowCount; $i++) {
        $student = $attendanceRows[$i] ?? null;
        $studentId = $student ? ($student['student_id'] ?? '') : '';
        $fullName = $student ? ($student['full_name'] ?? '') : '';
        $learningMethod = $student ? normalizeAttendanceLearningMethodForPrint($student['learning_method'] ?? '') : '';

        $html .= '<tr>';
        $html .= '<td class="attendance-cell-no">' . ($i + 1) . '</td>';
        $html .= '<td>' . escapeTextPreserveSpaces($studentId) . '</td>';
        $html .= '<td class="attendance-cell-name">' . escapeTextPreserveSpaces($fullName) . '</td>';
        $html .= '<td>&nbsp;</td>';
        $html .= '<td>' . escapeTextPreserveSpaces($learningMethod) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $html .= '</div>';
    return $html;
}

function normalizeAttendanceLearningMethodForPrint($method)
{
    $method = trim((string) $method);
    return in_array($method, ['พบกลุ่ม', 'ออนไลน์'], true) ? $method : 'พบกลุ่ม';
}

$fontDir = __DIR__ . '/fonts';
ensureDocumentFonts($fontDir);
$hasTHSarabun = file_exists($fontDir . '/THSarabunNew.ttf') && file_exists($fontDir . '/THSarabunNew-Bold.ttf');
$hasSarabun = file_exists($fontDir . '/Sarabun-Regular.ttf') && file_exists($fontDir . '/Sarabun-Bold.ttf');
$preferredFont = $hasTHSarabun ? 'THSarabunNew' : ($hasSarabun ? 'Sarabun' : 'Arial');
$fontFaceCss = '';
$fontAssetUrl = static function (string $filename) use ($fontDir): string {
    $fontPath = $fontDir . '/' . $filename;
    if (defined('SENA_PRINT_CAPTURE_LOCAL_ASSETS') && SENA_PRINT_CAPTURE_LOCAL_ASSETS && is_file($fontPath)) {
        return pathToLocalFileUrl($fontPath);
    }

    $dataUrl = fontFileDataUrl($fontPath);
    if ($dataUrl !== '') {
        return $dataUrl;
    }

    return pathToBrowserAssetUrl('fonts/' . $filename);
};
if ($hasTHSarabun) {
    $fontFaceCss .= '@font-face{font-family:"THSarabunNew";src:url("' . $fontAssetUrl('THSarabunNew.ttf') . '") format("truetype");font-weight:400;font-style:normal;font-display:block;}';
    $fontFaceCss .= '@font-face{font-family:"THSarabunNew";src:url("' . $fontAssetUrl('THSarabunNew-Bold.ttf') . '") format("truetype");font-weight:700;font-style:normal;font-display:block;}';
}
if ($hasSarabun) {
    $fontFaceCss .= '@font-face{font-family:"Sarabun";src:url("' . $fontAssetUrl('Sarabun-Regular.ttf') . '") format("truetype");font-weight:400;font-style:normal;font-display:block;}';
    $fontFaceCss .= '@font-face{font-family:"Sarabun";src:url("' . $fontAssetUrl('Sarabun-Bold.ttf') . '") format("truetype");font-weight:700;font-style:normal;font-display:block;}';
}

$dotSize = (int) ($settings['pdf_dot_size'] ?? 2);
if ($dotSize < 1 || $dotSize > 4) {
    $dotSize = 2;
}
$pdfFontSizePt = resolvePdfFontSizePt($settings);
$fontSizeOffsetPt = $pdfFontSizePt - 16;
$fontSizeOffsetPtCss = rtrim(rtrim(number_format($fontSizeOffsetPt, 2, '.', ''), '0'), '.');
$pdfLineSpacing = normalizePrintSettingInt($settings, 'pdf_line_spacing', 26, 20, 40);
$recordParagraphSpacing = normalizePrintSettingInt($settings, 'record_paragraph_spacing_px', 0, 0, 80);
$recordTextAlign = in_array($settings['record_text_align'] ?? '', ['left', 'justify', 'center', 'right'], true) ? $settings['record_text_align'] : 'justify';
$signatureFontSizePt = normalizePrintSettingInt($settings, 'signature_font_size_pt', $pdfFontSizePt, 10, 20);
$attendanceFontSizePt = normalizePrintSettingInt($settings, 'attendance_font_size_pt', $pdfFontSizePt, 10, 20);
$signatureFontSizeCss = rtrim(rtrim(number_format($signatureFontSizePt, 2, '.', ''), '0'), '.');
$attendanceFontSizeCss = rtrim(rtrim(number_format($attendanceFontSizePt, 2, '.', ''), '0'), '.');
$pdfPageMarginTopPx = normalizePrintSettingInt($settings, 'pdf_margin_top_px', PDF_PAGE_PADDING_TOP, 20, 140);
$pdfPageMarginRightPx = normalizePrintSettingInt($settings, 'pdf_margin_right_px', PDF_PAGE_PADDING_RIGHT, 20, 160);
$pdfPageMarginBottomPx = normalizePrintSettingInt($settings, 'pdf_margin_bottom_px', PDF_PAGE_PADDING_BOTTOM, 20, 140);
$pdfPageMarginLeftPx = normalizePrintSettingInt($settings, 'pdf_margin_left_px', PDF_PAGE_PADDING_LEFT, 20, 160);
$customImageWidth = isset($settings['image_width_px']) && $settings['image_width_px'] !== '';
$customImageHeight = isset($settings['image_height_px']) && $settings['image_height_px'] !== '';
$imageWidthPx = normalizePrintSettingInt($settings, 'image_width_px', 300, 180, 620);
$imageHeightPx = normalizePrintSettingInt($settings, 'image_height_px', 225, 120, 560);
$imageOffsetX = normalizePrintSettingInt($settings, 'image_offset_x', 0, -220, 220);
$imageOffsetY = normalizePrintSettingInt($settings, 'image_offset_y', 0, -220, 220);
$imageGapPx = normalizePrintSettingInt($settings, 'image_gap_px', 16, 0, 72);
$imageLayoutMode = ($settings['image_layout_mode'] ?? '') === 'free' ? 'free' : 'grid';
$imageCanvasWidthPx = max(120, PDF_PAGE_WIDTH - $pdfPageMarginLeftPx - $pdfPageMarginRightPx);
$imageCanvasHeightPx = max(160, PDF_PAGE_HEIGHT - $pdfPageMarginTopPx - $pdfPageMarginBottomPx - 54);
$hasGlobalCustomImageLayout = $customImageWidth || $customImageHeight
    || isset($settings['image_offset_x']) || isset($settings['image_offset_y']) || isset($settings['image_gap_px']);
$hasCustomImageLayout = $hasGlobalCustomImageLayout;
for ($imageSettingIndex = 1; $imageSettingIndex <= 4; $imageSettingIndex++) {
    foreach (['width_px', 'height_px'] as $imageSettingPart) {
        if (hasPrintSettingValue($settings, 'image_' . $imageSettingIndex . '_' . $imageSettingPart)) {
            $hasCustomImageLayout = true;
            break 2;
        }
    }
}
$dotRadius = max(0.5, min(0.7, 0.55 + (($dotSize - 2) * 0.05)));
$dotRadiusCss = rtrim(rtrim(number_format($dotRadius, 2, '.', ''), '0'), '.');
$dotCx = '0.75';
$dotCy = '1.25';
$dotVertCy = rtrim(rtrim(number_format($pdfLineSpacing - 0.85, 2, '.', ''), '0'), '.');
$svgWidth = 4;
$svgHeight = 2;
$dotFill = '%23000000';

// Soft, consistent round dots for every official dotted line.
$svgUrlHoriz = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'' . $svgWidth . '\' height=\'' . $svgHeight . '\' viewBox=\'0 0 ' . $svgWidth . ' ' . $svgHeight . '\'%3E%3Ccircle cx=\'' . $dotCx . '\' cy=\'' . $dotCy . '\' r=\'' . $dotRadiusCss . '\' fill=\'' . $dotFill . '\'/%3E%3C/svg%3E';

// Vertical / multi-line text dotted lines (dynamic line height, centered circle at bottom).
$svgUrlVert = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'' . $svgWidth . '\' height=\'' . $pdfLineSpacing . '\' viewBox=\'0 0 ' . $svgWidth . ' ' . $pdfLineSpacing . '\'%3E%3Ccircle cx=\'' . $dotCx . '\' cy=\'' . $dotVertCy . '\' r=\'' . $dotRadiusCss . '\' fill=\'' . $dotFill . '\'/%3E%3C/svg%3E';

$styles = '
<style>
    ' . $fontFaceCss . '
    :root {
        --font-size-offset-pt: ' . $fontSizeOffsetPtCss . 'pt;
        --signature-font-size: ' . $signatureFontSizeCss . 'pt;
        --attendance-name-font-size: ' . $attendanceFontSizeCss . 'pt;
    }
    @page {
        size: A4 ' . $paperOrientation . ';
        margin: ' . $pdfPageMarginTopPx . 'px 0 ' . $pdfPageMarginBottomPx . 'px 0;
    }
    body {
        margin: 0;
        padding: 0;
        font-family: "' . $preferredFont . '", sans-serif;
        font-weight: 400;
        font-synthesis: none;
        color: #000;
        font-size: ' . (16 + $fontSizeOffsetPt) . 'pt;
        line-height: 1.2;
        letter-spacing: 0;
        text-rendering: optimizeLegibility;
        -webkit-font-smoothing: antialiased;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .page,
    .page * {
        font-synthesis: none;
        letter-spacing: 0;
    }
    .page-break {
        break-before: page;
        page-break-before: always;
    }
    .page {
        width: ' . PDF_PAGE_WIDTH . 'px;
        min-height: ' . PDF_PAGE_HEIGHT . 'px;
        padding: ' . $pdfPageMarginTopPx . 'px ' . $pdfPageMarginRightPx . 'px ' . $pdfPageMarginBottomPx . 'px ' . $pdfPageMarginLeftPx . 'px;
        margin: 0 auto;
        box-sizing: border-box;
        position: relative;
        background: #fff;
    }
    @media print {
        .preprint-image-resize-handle, .preprint-move-handle { display:none !important; }
        .preprint-live-draggable, .preprint-live-editable { outline:none !important; box-shadow:none !important; background-color:transparent !important; }
        .image-free-canvas::before, .page::before, .page::after { display:none !important; }
        body {
            background: none;
            width: ' . PDF_PAGE_WIDTH . 'px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .page {
            width: ' . PDF_PAGE_WIDTH . 'px !important;
            max-width: ' . PDF_PAGE_WIDTH . 'px !important;
            margin: 0 !important;
            padding: 0 ' . $pdfPageMarginRightPx . 'px 0 ' . $pdfPageMarginLeftPx . 'px !important;
            min-height: 0 !important;
            box-shadow: none !important;
            border: none !important;
            page-break-after: always;
            box-sizing: border-box !important;
        }
        .page.image-page {
            overflow: hidden !important;
        }
        /* Force fixed-width for all text containers when printing */
        .dotted-text-area,
        .dotted-text-content,
        .dotted-text-content p.official-para,
        .dotted-text-content p {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .dotted-text-content p.official-para,
        .dotted-text-content p {
            text-align: ' . $recordTextAlign . ' !important;
            text-justify: inter-character !important;
            -webkit-text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ' !important;
            text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ' !important;
            white-space: break-spaces !important;
            overflow: visible !important;
            overflow-wrap: break-word !important;
            word-break: break-word !important;
        }
        .official-line {
            text-align: ' . $recordTextAlign . ' !important;
            text-justify: inter-character !important;
            text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ' !important;
            white-space: break-spaces !important;
            overflow: visible !important;
            overflow-wrap: break-word !important;
            word-break: break-word !important;
        }
        /* Hide empty background when no text */
        .dotted-text-area {
            min-height: 0 !important;
        }
        .row-table {
            width: 100% !important;
            table-layout: fixed !important;
        }
    }
    h2 {
        text-align: center;
        font-size: ' . (20 + $fontSizeOffsetPt) . 'pt;
        line-height: 1.1;
        font-weight: bold;
        margin: 0 0 8px 0;
    }
    .row-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin-bottom: 6px;
    }
    .row-table td {
        vertical-align: bottom;
        padding: 0 4px 2px;
        font-size: ' . (16 + $fontSizeOffsetPt) . 'pt;
        line-height: 1.2;
    }
    .row-spacer {
        padding: 0;
    }
    .label-cell {
        white-space: nowrap;
        padding-right: 6px;
        overflow: visible;
        box-sizing: border-box;
    }
    .nowrap {
        white-space: nowrap;
    }
    .dotted-line {
        border-bottom: none;
        background-image: url("' . $svgUrlHoriz . '");
        background-size: ' . $svgWidth . 'px ' . $svgHeight . 'px;
        background-position: left bottom;
        background-repeat: repeat-x;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
        min-height: 26px;
        line-height: 1.2;
        padding: 0 5px 2px;
        image-rendering: auto;
    }
    .section-title {
        font-size: ' . (17 + $fontSizeOffsetPt) . 'pt;
        font-weight: bold;
        margin-top: 8px;
        margin-bottom: 2px;
        break-after: avoid;
        page-break-after: avoid;
    }
    .pdf-preview-title-block {
        position: relative;
        width: 100%;
        padding: 4px 8px 6px;
        box-sizing: border-box;
    }
    .pdf-preview-title-text {
        margin-top: 0;
        margin-bottom: 0;
    }
    /* ── Dotted text area ─────────────────────────────────────────────── */
    .dotted-text-area {
        margin-bottom: 2px;
        /* Background dots repeat every line-height px vertically.
           svgUrlVert has a dot at (lineSpacing-radius) from top, in a
           lineSpacing-tall viewport - this places the dot at the bottom
           of every text line. */
        background-image: url("' . $svgUrlVert . '");
        background-size: ' . $svgWidth . 'px ' . $pdfLineSpacing . 'px;
        background-position: left 0;
        background-repeat: repeat;
        image-rendering: auto;
    }
    .dotted-text-area--fixed-lines {
        overflow: hidden;
    }
    /* Wrapper div - must have no spacing so background aligns with text */
    .dotted-text-content {
        margin: 0;
        padding: 0;
        font-size: ' . (16 + $fontSizeOffsetPt) . 'pt;
        line-height: ' . $pdfLineSpacing . 'px;
    }
    .dotted-text-content--prewrapped {
        text-align: ' . $recordTextAlign . ';
        text-indent: ' . officialFirstLineIndentPx() . 'px;
        white-space: break-spaces;
        tab-size: 4;
    }
    .dotted-text-area { margin-bottom: ' . $recordParagraphSpacing . 'px; }
    .preprint-live-editable { tab-size: 4; }
    .sig-box-line .preprint-live-editable[data-preprint-allow-br],
    .sig-box-line-tight .preprint-live-editable[data-preprint-allow-br] { display:block; min-height:1em; }
    .preprint-live-editable[data-preprint-allow-br="1"] { white-space: break-spaces; }
    .dotted-text-content--prewrapped:has(.official-para) { text-indent: 0; }
    /* ── Paragraph-based justified text (เอกสารราชการ) ──────────────────── */
    /* IMPORTANT: text-align:justify only works when CSS wraps the text
       (multi-line). We output whole paragraphs so the browser wraps and
       distributes spacing. text-justify:inter-character makes Thai fill
       the line by spacing between characters (not just between words). */
    .dotted-text-content p.official-para,
    .dotted-text-content p {
        display: block;
        margin: 0;
        padding: 0;
        font-size: ' . (16 + $fontSizeOffsetPt) . 'pt;
        line-height: ' . $pdfLineSpacing . 'px;
        text-align: ' . $recordTextAlign . ';
        -webkit-text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ';
        text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ';
        text-justify: inter-character;
        -webkit-hyphens: auto;
        hyphens: auto;
        white-space: break-spaces;
        tab-size: 4;
        overflow-wrap: break-word;
        word-break: break-word;
        width: 100%;
        box-sizing: border-box;
    }
    .official-para-first {
        text-indent: ' . officialFirstLineIndentPx() . 'px;
    }
    /* Legacy / fallback: single-line spans (not used in main text areas) */
    .official-paragraph { margin: 0; }
    .official-line {
        display: block;
        min-height: ' . $pdfLineSpacing . 'px;
        line-height: ' . $pdfLineSpacing . 'px;
        padding: 0 0 1px 0;
        background-image: url("' . $svgUrlHoriz . '");
        background-size: ' . $svgWidth . 'px ' . $svgHeight . 'px;
        background-position: left bottom;
        background-repeat: repeat-x;
        box-sizing: border-box;
        white-space: normal;
        overflow-wrap: break-word;
        word-break: break-word;
        overflow: visible;
        text-align: justify;
        text-justify: inter-character;
        text-align-last: ' . ($recordTextAlign === 'justify' ? 'left' : $recordTextAlign) . ';
        image-rendering: auto;
        width: 100%;
    }
    .official-line-last { text-align: left; }
    .official-line-first { text-indent: ' . officialFirstLineIndentPx() . 'px; }
    .official-line-normal { text-indent: 0; }
    .separator {
        border-top: 1px solid #000;
        margin: 4px 0;
    }
    .manual-page-break {
        display: block;
        width: 100%;
        height: ' . (($isHtmlPreview && !$isClientPdfExport) ? '0' : '1px') . ';
        margin: ' . (($isHtmlPreview && !$isClientPdfExport) ? '12px' : '0') . ' 0 !important;
        padding: 0;
        clear: both;
        position: relative;
        border-top: ' . (($isHtmlPreview && !$isClientPdfExport) ? '2px dashed #ef4444' : 'none') . ';
        page-break-before: always;
        break-before: page;
        opacity: ' . (($isHtmlPreview && !$isClientPdfExport) ? '1' : '0') . ';
        pointer-events: none;
    }
    ' . (($isHtmlPreview && !$isClientPdfExport) ? '
    .manual-page-break::after {
        content: "✂️ จุดตัดขึ้นหน้าใหม่";
        position: absolute;
        right: 0;
        top: -12px;
        background: #ef4444;
        color: #fff;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 10px;
        font-family: sans-serif;
    }
    ' : '') . '
    .single-line-dot {
        border-bottom: none;
        background-image: url("' . $svgUrlHoriz . '");
        background-size: ' . $svgWidth . 'px ' . $svgHeight . 'px;
        background-position: left bottom;
        background-repeat: repeat-x;
        height: 22px;
        margin-bottom: 2px;
        image-rendering: auto;
    }
    .signature-row {
        table-layout: fixed;
        border-collapse: collapse;
        margin-bottom: 0;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .signature-row td {
        vertical-align: top;
        padding: 0;
    }
    .signature-cell {
        width: 100%;
    }
    .pdf-preview-block {
        position: relative;
        display: inline-block;
        min-width: 250px;
        max-width: 100%;
        padding: 4px 8px 6px;
        box-sizing: border-box;
    }
    .pdf-preview-block--wide {
        display: block;
        width: 100%;
    }
    .pdf-preview-handle-space {
        height: 0;
        margin-bottom: 0;
        visibility: hidden;
    }
    .pdf-absolute-block {
        position: absolute;
    }
    .pdf-section-block {
        width: 100%;
        box-sizing: border-box;
    }
    .pdf-flow-group {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .signature-flow-group {
        margin-bottom: 0;
    }
    .signature-flow-section {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .signature-flow-group--keep {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-preview-block--flow-signature {
        display: inline-block;
        min-width: 0;
        max-width: 100%;
        padding: 4px 8px 6px;
        box-sizing: border-box;
        text-align: center;
    }
    .pdf-absolute-section-block {
        position: absolute;
        box-sizing: border-box;
    }
    .pdf-preview-section-block {
        padding: 0;
        box-sizing: border-box;
    }
    .pdf-placeholder {
        visibility: hidden;
    }
    .sig-box {
        display: inline-block;
        text-align: center;
        font-size: 16pt;
        line-height: 1.1;
        max-width: 100%;
    }
    .pdf-preview-block--signature-centered {
        width: 430px;
        max-width: 100%;
        margin-left: auto;
        margin-right: auto;
    }
    .sig-box-line,
    .sig-box-line-tight {
        margin-bottom: 1px;
        white-space: nowrap;
    }
    .sig-line-table {
        margin: 0 auto 1px auto;
        border-collapse: collapse;
    }
    .sig-line-table td {
        font-size: 16pt;
        line-height: 1.1;
        padding: 0;
        vertical-align: bottom;
        white-space: nowrap;
    }
    .sig-line-prefix,
    .sig-line-suffix {
        padding: 0 2px;
    }
    .sig-line-suffix-placeholder {
        color: #fff;
        font-size: 1px;
        line-height: 1;
        padding: 0;
    }
    .sig-line-dot {
        border-bottom: none;
        background-image: url("' . $svgUrlHoriz . '");
        background-size: ' . $svgWidth . 'px ' . $svgHeight . 'px;
        background-position: left bottom;
        background-repeat: repeat-x;
        height: 18px;
        image-rendering: auto;
    }
    .image-title {
        text-align: center;
        font-size: ' . (20 + $fontSizeOffsetPt) . 'pt;
        font-weight: bold;
        margin: 0 0 10px 0;
        page-break-after: avoid;
        break-after: avoid;
    }
    .image-layout-inner {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .image-free-canvas {
        position: relative;
        width: ' . $imageCanvasWidthPx . 'px;
        height: ' . $imageCanvasHeightPx . 'px;
        max-width: 100%;
        overflow: hidden;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .image-free-item {
        position: absolute;
        box-sizing: border-box;
        padding: 0;
        border: 0;
        background: transparent;
        overflow: visible;
        transform-origin: center;
    }
    .image-free-media {
        position: relative;
        width: 100%;
        height: 100%;
        overflow: hidden;
        background: transparent;
    }
    .image-free-media img,
    .image-free-media .settings-preview-image-placeholder,
    .image-free-media .missing-image-note {
        display: block;
        width: 100%;
        height: 100%;
        max-width: none;
        max-height: none;
        box-sizing: border-box;
        margin: 0;
    }
    .image-free-media img {
        position: absolute;
        left: 50%;
        top: 50%;
        transform-origin: center;
        pointer-events: none;
    }
    .image-grid-two {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .image-grid-four {
        width: 100%;
        border-collapse: separate;
        border-spacing: 16px 16px;
        margin-top: 8px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .image-grid-two tr,
    .image-grid-four tr,
    .image-shell-two,
    .image-shell-four {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .image-shell-two {
        width: 300px;
        height: 225px;
        border: none;
        background: transparent;
        padding: 2px;
        text-align: center;
        vertical-align: middle;
        overflow: visible;
    }
    .image-shell-four {
        width: 300px;
        height: 225px;
        border: none;
        background: transparent;
        padding: 2px;
        text-align: center;
        vertical-align: middle;
        overflow: visible;
    }
    .image-fill {
        display: block;
        max-width: 100%;
        max-height: 100%;
        width: 100%;
        height: 100%;
        margin: 0 auto;
        object-fit: fill;
    }
    .missing-image-note {
        display: block;
        width: 100%;
        padding: 24px 10px;
        border: 0.5px dashed #b83232;
        background: #fff5f5;
        color: #9b2c2c;
        text-align: center;
        font-size: 16px;
        line-height: 1.45;
    }
    .missing-image-note small {
        color: #7b5d5d;
        font-size: 12px;
    }
    .attendance-page {
        font-size: ' . (13.875 + $fontSizeOffsetPt) . 'pt;
    }
    .attendance-title-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        font-size: ' . (16.5 + $fontSizeOffsetPt) . 'pt;
        font-weight: bold;
        margin: 14px 0 22px 0;
        white-space: nowrap;
    }
    .attendance-title-table td {
        padding: 0 4px 2px 4px;
        line-height: 1.1;
        vertical-align: bottom;
        overflow: hidden;
    }
    .attendance-title-main {
        width: 19%;
        text-align: left;
    }
    .attendance-title-label {
        width: 5.5%;
        text-align: right;
    }
    .attendance-title-dot {
        border-bottom: none;
        background-image: url("' . $svgUrlHoriz . '");
        background-size: ' . $svgWidth . 'px ' . $svgHeight . 'px;
        background-position: left bottom;
        background-repeat: repeat-x;
        image-rendering: auto;
        text-align: center;
        width: 10%;
    }
    .attendance-title-dot-month {
        width: 22%;
    }
    .attendance-title-dot-year {
        width: 9%;
    }
    .attendance-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: ' . (13.5 + $fontSizeOffsetPt) . 'pt;
    }
    .attendance-table th,
    .attendance-table td {
        border: 1px solid #000;
        padding: 4px 6px;
        vertical-align: middle;
        line-height: 1.1;
    }
    .attendance-table th {
        text-align: center;
        font-weight: bold;
        height: 54px;
        font-size: ' . (14.25 + $fontSizeOffsetPt) . 'pt;
    }
    .attendance-table td {
        height: 24px;
        font-size: ' . (12.75 + $fontSizeOffsetPt) . 'pt;
    }
    .attendance-col-no {
        width: 7%;
    }
    .attendance-col-id {
        width: 22%;
    }
    .attendance-col-name {
        width: 32%;
    }
    .attendance-col-sign {
        width: 17%;
    }
    .attendance-col-note {
        width: 22%;
    }
    .attendance-cell-no,
    .attendance-table td:nth-child(2),
    .attendance-table td:nth-child(4),
    .attendance-table td:nth-child(5) {
        text-align: center;
    }
    .attendance-cell-name {
        text-align: left;
        font-size: 16pt !important;
    }
</style>
';

$page1Html = $styles;
$page1Html .= '<div class="page">';
$page1Html .= '<h2>บันทึกหลังการสอนการเรียนรู้รายสัปดาห์</h2>';

$page1Html .= buildInfoRow([
    ['label' => 'ศูนย์การเรียนรู้', 'label_width' => '18%', 'value' => 'ตำบล' . ($record['tambon_name'] ?? ''), 'width' => '22%', 'nowrap' => true],
    ['label' => 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ', 'label_width' => '36%', 'value' => 'เสนา', 'width' => '16%', 'nowrap' => true],
]);

$page1Html .= buildInfoRow([
    ['label' => 'สัปดาห์ที่', 'label_width' => '9%', 'value' => $record['week'] ?? '', 'width' => '8%'],
    ['label' => 'วันที่', 'label_width' => '7%', 'value' => $day, 'width' => '9%'],
    ['label' => 'เดือน', 'label_width' => '7%', 'value' => $month, 'width' => '17%'],
    ['label' => 'พ.ศ.', 'label_width' => '7%', 'value' => $year, 'width' => '13%'],
]);

$page1Html .= buildInfoRow([
    ['label' => 'ระดับ', 'label_width' => '8%', 'value' => $record['level'] ?? '', 'width' => '27%', 'nowrap' => true],
    ['label' => 'ภาคเรียนที่', 'label_width' => '17%', 'value' => $record['semester'] ?? '', 'width' => '7%'],
    ['label' => 'ปีการศึกษา', 'label_width' => '17%', 'value' => $record['year'] ?? '', 'width' => '9%'],
]);

if (!empty($record['class_name']) || !empty($record['class_group_code'])) {
    $page1Html .= buildInfoRow([
        ['label' => 'กลุ่มเรียน', 'label_width' => '12%', 'value' => $record['class_name'] ?: $record['class_group_code'], 'width' => '88%'],
    ]);
}

$page1Html .= buildInfoRow([
    ['label' => 'รายวิชา :', 'label_width' => '10%', 'value' => $record['subject'] ?? '', 'width' => '36%'],
    ['label' => 'รหัสวิชา :', 'label_width' => '11%', 'value' => $record['subject_code'] ?? '', 'width' => '28%'],
]);

$page1Html .= buildInfoRow([
    ['label' => 'ผู้เรียนทั้งหมด', 'label_width' => '14%', 'value' => $record['total'] ?? '', 'width' => '8%'],
    ['label' => 'คน เข้าเรียน', 'label_width' => '13%', 'value' => $record['present'] ?? '', 'width' => '8%'],
    ['label' => 'คน ไม่มาเรียน', 'label_width' => '15%', 'value' => $absent, 'width' => '8%'],
    ['label' => 'คน', 'label_width' => '7%', 'value' => null],
]);

$page1Html .= '<div class="section-title">' . preprintEditableHtml('record_section1_title', escapeTextPreserveSpaces($recordSection1Title), false, true) . '</div>';
$page1Html .= buildDottedTextArea($record['result'] ?? '', 1, null, 'record_result');

$page1Html .= '<div class="section-title">' . preprintEditableHtml('record_section2_title', escapeTextPreserveSpaces($recordSection2Title), false, true) . '</div>';
$page1Html .= buildDottedTextArea($record['problem'] ?? '', 1, null, 'record_problem');

$page1Html .= '<div class="section-title">' . preprintEditableHtml('record_section3_title', escapeTextPreserveSpaces($recordSection3Title), false, true) . '</div>';
$page1Html .= buildDottedTextArea($record['suggestion'] ?? '', 1, null, 'record_suggestion');

$sig1DisplayName = escapeTextPreserveSpaces($sig1_name !== '' ? $sig1_name : ($record['teacher_name'] ?? ''));
$sig2DisplayName = escapeTextPreserveSpaces($sig2_name !== '' ? $sig2_name : '........................................................');
$sig3DisplayName = escapeTextPreserveSpaces($sig3_name !== '' ? $sig3_name : '........................................................');

$sig1Content = '
    ' . buildShiftedSignaturePart(buildSignatureLine($sig1_sign_prefix, $sig1_dot_width, $sig1_sign_suffix, [
        'prefix_offset_x' => $sig1_sign_prefix_offset_x,
        'prefix_offset_y' => $sig1_sign_prefix_offset_y,
        'prefix_setting' => 'sig1_sign_prefix',
        'suffix_setting' => 'sig1_sign_suffix',
    ]), $sig1_line_offset_x, $sig1_line_offset_y, 'sig-box-line-tight') . '
    ' . buildShiftedSignaturePart('( ' . preprintEditableHtml('sig1_name', $sig1DisplayName) . ' )', $sig1_name_offset_x, $sig1_name_offset_y, 'sig-box-line') . '
    ' . buildShiftedSignaturePart(preprintEditableHtml('sig1_pos', escapeTextPreserveSpacesAllowBr($sig1_pos !== '' ? $sig1_pos : 'ครูผู้สอน'), true), $sig1_pos_offset_x, $sig1_pos_offset_y, 'sig-box-line') . '
';

$sig2Content = '
    ' . buildShiftedSignaturePart(buildSignatureLine($sig2_sign_label, $sig2_dot_width, '', [
        'prefix_offset_x' => $sig2_sign_label_offset_x,
        'prefix_offset_y' => $sig2_sign_label_offset_y,
        'prefix_setting' => 'sig2_sign_label',
    ]), $sig2_line_offset_x, $sig2_line_offset_y, 'sig-box-line-tight') . '
    ' . buildShiftedSignaturePart('( ' . preprintEditableHtml('sig2_name', $sig2DisplayName) . ' )', $sig2_name_offset_x, $sig2_name_offset_y, 'sig-box-line-tight') . '
    ' . buildShiftedSignaturePart(preprintEditableHtml('sig2_pos', escapeTextPreserveSpacesAllowBr($sig2_pos), true), $sig2_pos_offset_x, $sig2_pos_offset_y, 'sig-box-line') . '
';

$sig3Content = '
    ' . buildShiftedSignaturePart(buildSignatureLine($sig3_sign_label, $sig3_dot_width, '', [
        'prefix_offset_x' => $sig3_sign_label_offset_x,
        'prefix_offset_y' => $sig3_sign_label_offset_y,
        'prefix_setting' => 'sig3_sign_label',
    ]), $sig3_line_offset_x, $sig3_line_offset_y, 'sig-box-line-tight') . '
    ' . buildShiftedSignaturePart('( ' . preprintEditableHtml('sig3_name', $sig3DisplayName) . ' )', $sig3_name_offset_x, $sig3_name_offset_y, 'sig-box-line-tight') . '
    ' . buildShiftedSignaturePart(preprintEditableHtml('sig3_pos', escapeTextPreserveSpacesAllowBr($sig3_pos), true), $sig3_pos_offset_x, $sig3_pos_offset_y, 'sig-box-line') . '
';

$page1Html .= buildFlowSignatureBlock($sig1_align, $sig1_mt, $sig1Content, PDF_SIGNATURE_WIDTH, [
    'offset_x' => $sig1_offset,
    'offset_y' => $sig1_offset_y,
    'editor_x' => 'sig1_offset',
    'editor_y' => 'sig1_offset_y',
    'editor_label' => 'ผู้บันทึก',
]);

$sig2KeepClass = count(getDottedTextContentLines($record['supervisor_comment'] ?? '')) <= 4 ? ' signature-flow-group--keep' : '';
$sig2GroupStyle = 'margin-top:' . (PDF_FLOW_SIG2_SECTION_MARGIN_TOP + $sig2_block_offset_y) . 'px;';
if ($sig2_block_offset_x !== 0) {
    $sig2GroupStyle .= ' transform:translateX(' . $sig2_block_offset_x . 'px);';
}
$page1Html .= '<div class="signature-flow-group signature-flow-group--sig2' . $sig2KeepClass . '" style="' . $sig2GroupStyle . '">';
$page1Html .= '<div class="separator"></div>';
$page1Html .= buildShiftedSectionTitle($sig2_title, $sig2_title_offset_x, $sig2_title_offset_y, 'sig2_title');
$page1Html .= buildDottedTextArea($record['supervisor_comment'] ?? '', 1, null, 'record_supervisor_comment');
$page1Html .= buildFlowSignatureBlock($sig2_align, $sig2_mt, $sig2Content, PDF_SIGNATURE_WIDTH, [
    'offset_x' => $sig2_offset,
    'offset_y' => $sig2_offset_y,
    'editor_x' => 'sig2_offset',
    'editor_y' => 'sig2_offset_y',
    'editor_label' => 'หัวหน้างาน',
]);
$page1Html .= '</div>';

$sig3GroupStyle = 'margin-top:' . (PDF_FLOW_SIG3_SECTION_MARGIN_TOP + $sig3_block_offset_y) . 'px;';
if ($sig3_block_offset_x !== 0) {
    $sig3GroupStyle .= ' transform:translateX(' . $sig3_block_offset_x . 'px);';
}
$page1Html .= '<div class="signature-flow-group signature-flow-group--sig3 signature-flow-group--keep" style="' . $sig3GroupStyle . '">';
$page1Html .= buildShiftedSectionTitle($sig3_title, $sig3_title_offset_x, $sig3_title_offset_y, 'sig3_title');
$page1Html .= '<div class="single-line-dot"></div>';
$page1Html .= buildFlowSignatureBlock($sig3_align, $sig3_mt, $sig3Content, PDF_SIGNATURE_WIDTH, [
    'offset_x' => $sig3_offset,
    'offset_y' => $sig3_offset_y,
    'editor_x' => 'sig3_offset',
    'editor_y' => 'sig3_offset_y',
    'editor_label' => 'ผู้อำนวยการ',
]);
$page1Html .= '</div>';

$page1Html .= '</div>';

$imagesHtml = '';
if ($showImagePage && !empty($images)) {
    $imageLayoutStyle = $hasGlobalCustomImageLayout
        ? ' style="transform:translate(' . $imageOffsetX . 'px,' . $imageOffsetY . 'px); transform-origin:center top;"'
        : '';
    $imageCellStyle = $hasGlobalCustomImageLayout
        ? ' style="width:' . $imageWidthPx . 'px; height:' . $imageHeightPx . 'px;"'
        : '';
    $imageImgStyle = $hasGlobalCustomImageLayout
        ? ' style="width:' . $imageWidthPx . 'px; height:' . $imageHeightPx . 'px; max-width:none; max-height:none; object-fit:fill;"'
        : '';
    $imageTableFourStyle = $hasCustomImageLayout
        ? ' style="width:auto; margin:8px auto 0; border-spacing:0; border-collapse:separate;"'
        : '';
    $imageTableTwoStyle = $hasCustomImageLayout
        ? ' style="width:auto; margin:0 auto; border-spacing:0 ' . $imageGapPx . 'px;"'
        : '';

    $imagesHtml .= '<div class="page-break"></div>';
    $imagesHtml .= '<div class="page image-page">';
    $imagesHtml .= '<div class="image-title">ภาพประกอบการจัดการเรียนการสอน</div>';
    $imagesHtml .= '<div class="image-layout-inner"' . $imageLayoutStyle . '>';

    if ($imageLayoutMode === 'free') {
        $imagesHtml .= '<div class="image-free-canvas">';
        foreach ($images as $imageIndex => $image) {
            $imageNumber = $imageIndex + 1;
            $imageId = (string) ($image['id'] ?? 'legacy-' . $imageNumber);
            $itemWidthPx = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_width_px', $imageWidthPx, 60, 1000);
            $itemHeightPx = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_height_px', $imageHeightPx, 60, 950);
            $itemX = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_x_px', 0, 0, 1200);
            $itemY = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_y_px', 0, 0, 1100);
            $itemObjectFit = in_array(($settings['image_' . $imageNumber . '_object_fit'] ?? 'fill'), ['contain', 'cover', 'fill'], true)
                ? $settings['image_' . $imageNumber . '_object_fit']
                : 'fill';
            $itemRotation = in_array((string) ($settings['image_' . $imageNumber . '_rotation_deg'] ?? '0'), ['0', '90', '180', '270'], true)
                ? (int) $settings['image_' . $imageNumber . '_rotation_deg']
                : 0;
            $itemLockAspect = ($settings['image_' . $imageNumber . '_lock_aspect'] ?? '0') === '1' ? '1' : '0';
            $itemCropX = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_crop_x', 50, 0, 100);
            $itemCropY = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_crop_y', 50, 0, 100);
            $itemStyle = ' style="left:' . $itemX . 'px; top:' . $itemY . 'px; width:' . $itemWidthPx . 'px; height:' . $itemHeightPx . 'px;"';
            $itemEditorClass = $isLayoutEditorPreview ? ' preprint-live-draggable' : '';
            $itemEditorAttributes = $isLayoutEditorPreview
                ? ' tabindex="0" role="button" aria-label="ลากหรือปรับขนาดรูปที่ ' . $imageNumber . '" data-layout-kind="image" data-layout-position="absolute" data-layout-label="รูปที่ ' . $imageNumber . '"'
                    . ' data-layout-image-id="' . htmlspecialchars($imageId, ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-layout-x-key="image_' . $imageNumber . '_x_px" data-layout-y-key="image_' . $imageNumber . '_y_px"'
                    . ' data-layout-width-key="image_' . $imageNumber . '_width_px" data-layout-height-key="image_' . $imageNumber . '_height_px"'
                    . ' data-layout-fit-key="image_' . $imageNumber . '_object_fit" data-layout-rotation-key="image_' . $imageNumber . '_rotation_deg" data-layout-lock-key="image_' . $imageNumber . '_lock_aspect"'
                    . ' data-layout-x="' . $itemX . '" data-layout-y="' . $itemY . '"'
                    . ' data-layout-width="' . $itemWidthPx . '" data-layout-height="' . $itemHeightPx . '"'
                    . ' data-layout-fit="' . $itemObjectFit . '" data-layout-rotation="' . $itemRotation . '"'
                    . ' data-layout-lock-aspect="' . $itemLockAspect . '"'
                    . ' data-layout-crop-x-key="image_' . $imageNumber . '_crop_x" data-layout-crop-y-key="image_' . $imageNumber . '_crop_y"'
                    . ' data-layout-crop-x="' . $itemCropX . '" data-layout-crop-y="' . $itemCropY . '"'
                : '';
            $imagePath = resolveLocalPath($image['file_path'] ?? '');

            $imagesHtml .= '<div class="image-free-item' . $itemEditorClass . '"' . $itemEditorAttributes . $itemStyle . '>';
            $imagesHtml .= '<div class="image-free-media">';
            if ($imagePath && file_exists($imagePath)) {
                $imageSrc = resolveImageSrc($image['file_path'] ?? '', $imagePath, $isHtmlPreview);
                $rotatedQuarterTurn = in_array($itemRotation, [90, 270], true);
                $rotatedWidth = $rotatedQuarterTurn ? $itemHeightPx . 'px' : '100%';
                $rotatedHeight = $rotatedQuarterTurn ? $itemWidthPx . 'px' : '100%';
                $imagesHtml .= '<img src="' . htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') . '" alt="ภาพประกอบการเรียนการสอน รูปที่ ' . $imageNumber . '" style="width:' . $rotatedWidth . '; height:' . $rotatedHeight . '; object-fit:' . $itemObjectFit . '; object-position:' . $itemCropX . '% ' . $itemCropY . '%; transform:translate(-50%,-50%) rotate(' . $itemRotation . 'deg);" />';
            } elseif ($isSettingsPreview) {
                $imagesHtml .= '<div class="settings-preview-image-placeholder"><strong>รูปที่ ' . $imageNumber . '</strong><span>ลากเพื่อย้าย ปรับกว้าง สูง หรือทั้งสองด้าน</span></div>';
            } else {
                $missingPath = htmlspecialchars($image['file_path'] ?? '', ENT_QUOTES, 'UTF-8');
                $imagesHtml .= '<div class="missing-image-note">ไม่พบไฟล์รูป<br><small>' . $missingPath . '</small></div>';
            }
            $imagesHtml .= '</div>';
            if ($isLayoutEditorPreview) {
                foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $handle) {
                    $imagesHtml .= '<span class="preprint-image-resize-handle" data-layout-resize="' . $handle . '" title="ปรับขนาดรูป" aria-hidden="true"></span>';
                }
            }
            $imagesHtml .= '</div>';
        }
        $imagesHtml .= '</div>';
    } elseif (count($images) <= 2) {
        $imagesHtml .= '<table class="image-grid-two"' . $imageTableTwoStyle . '>';
        foreach ($images as $imageIndex => $image) {
            $imageNumber = $imageIndex + 1;
            $itemHasOffsetCustom = hasPrintSettingValue($settings, 'image_' . $imageNumber . '_offset_x')
                || hasPrintSettingValue($settings, 'image_' . $imageNumber . '_offset_y');
            $itemWidthPx = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_width_px', $imageWidthPx, 180, 620);
            $itemHeightPx = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_height_px', $imageHeightPx, 120, 560);
            $itemOffsetX = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_offset_x', 0, -220, 220);
            $itemOffsetY = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_offset_y', 0, -220, 220);
            $itemCellStyleParts = [];
            $itemCellStyleParts[] = 'width:' . $itemWidthPx . 'px';
            $itemCellStyleParts[] = 'height:' . $itemHeightPx . 'px';
            if ($itemHasOffsetCustom) {
                $itemCellStyleParts[] = 'transform:translate(' . $itemOffsetX . 'px,' . $itemOffsetY . 'px)';
            }
            $itemCellStyle = $itemCellStyleParts
                ? ' style="' . implode('; ', $itemCellStyleParts) . ';"'
                : $imageCellStyle;
            $itemImageStyle = ' style="width:' . $itemWidthPx . 'px; height:' . $itemHeightPx . 'px; max-width:none; max-height:none; object-fit:fill;"';
            $itemEditorClass = $isLayoutEditorPreview ? ' preprint-live-draggable' : '';
            $itemEditorAttributes = $isLayoutEditorPreview
                ? ' tabindex="0" role="button" aria-label="ลากหรือปรับขนาดรูปที่ ' . $imageNumber . '" data-layout-kind="image" data-layout-label="รูปที่ ' . $imageNumber . '"'
                    . ' data-layout-x-key="image_' . $imageNumber . '_offset_x" data-layout-y-key="image_' . $imageNumber . '_offset_y"'
                    . ' data-layout-width-key="image_' . $imageNumber . '_width_px" data-layout-height-key="image_' . $imageNumber . '_height_px"'
                    . ' data-layout-x="' . $itemOffsetX . '" data-layout-y="' . $itemOffsetY . '"'
                    . ' data-layout-width="' . $itemWidthPx . '" data-layout-height="' . $itemHeightPx . '"'
                : '';
            $imagePath = resolveLocalPath($image['file_path'] ?? '');
            $imagesHtml .= '<tr>';
            $imagesHtml .= '<td style="width:11%;"></td>';
            $imagesHtml .= '<td class="image-shell-two' . $itemEditorClass . '"' . $itemEditorAttributes . $itemCellStyle . '>';
            if ($imagePath && file_exists($imagePath)) {
                $imageSrc = resolveImageSrc($image['file_path'] ?? '', $imagePath, $isHtmlPreview);
                $imagesHtml .= '<img src="' . htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') . '"' . $itemImageStyle . ' />';
            } elseif ($isSettingsPreview) {
                $imagesHtml .= '<div class="settings-preview-image-placeholder"><strong>รูปที่ ' . $imageNumber . '</strong><span>ลากเพื่อย้าย ใช้จุดมุมเพื่อปรับขนาด</span></div>';
            } else {
                $missingPath = htmlspecialchars($image['file_path'] ?? '', ENT_QUOTES, 'UTF-8');
                $imagesHtml .= '<div class="missing-image-note">ไม่พบไฟล์รูป<br><small>' . $missingPath . '</small></div>';
            }
            if ($isLayoutEditorPreview) {
                $imagesHtml .= '<span class="preprint-image-resize-corner" data-layout-resize="1" aria-hidden="true"></span>';
            }
            $imagesHtml .= '</td>';
            $imagesHtml .= '<td style="width:11%;"></td>';
            $imagesHtml .= '</tr>';
        }
        $imagesHtml .= '</table>';
    } else {
        $imagesHtml .= '<table class="image-grid-four"' . $imageTableFourStyle . '>';
        $imageRows = array_chunk($images, 2);
        $imageRowCount = count($imageRows);
        $fourImageDimensions = [];
        foreach ($images as $imageIndex => $_image) {
            $imageNumber = $imageIndex + 1;
            $fourImageDimensions[$imageNumber] = [
                'width' => normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_width_px', $imageWidthPx, 180, 620),
                'height' => normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_height_px', $imageHeightPx, 120, 560),
            ];
        }
        $fourImageColumnWidths = [0, 0];
        $fourImageRowHeights = array_fill(0, max(1, $imageRowCount), 0);
        foreach ($imageRows as $rowIndex => $rowImagesForMeasure) {
            foreach ($rowImagesForMeasure as $cellIndex => $_image) {
                $imageNumber = ($rowIndex * 2) + $cellIndex + 1;
                $fourImageColumnWidths[$cellIndex] = max(
                    $fourImageColumnWidths[$cellIndex],
                    $fourImageDimensions[$imageNumber]['width'] ?? $imageWidthPx
                );
                $fourImageRowHeights[$rowIndex] = max(
                    $fourImageRowHeights[$rowIndex],
                    $fourImageDimensions[$imageNumber]['height'] ?? $imageHeightPx
                );
            }
        }
        $fourImageGridWidth = array_sum($fourImageColumnWidths) + $imageGapPx;
        $fourImageAvailableWidth = max(240, PDF_CONTENT_WIDTH - (abs($imageOffsetX) * 2));
        $fourImageFitScale = $fourImageGridWidth > $fourImageAvailableWidth
            ? $fourImageAvailableWidth / max(1, $fourImageGridWidth)
            : 1;
        foreach ($imageRows as $rowIndex => $rowImages) {
            $imagesHtml .= '<tr>';
            foreach ($rowImages as $cellIndex => $image) {
                $imageNumber = ($rowIndex * 2) + $cellIndex + 1;
                $itemHasOffsetCustom = hasPrintSettingValue($settings, 'image_' . $imageNumber . '_offset_x')
                    || hasPrintSettingValue($settings, 'image_' . $imageNumber . '_offset_y');
                $itemWidthPx = $fourImageDimensions[$imageNumber]['width'] ?? $imageWidthPx;
                $itemHeightPx = $fourImageDimensions[$imageNumber]['height'] ?? $imageHeightPx;
                $displayWidthPx = (int) max(1, floor($itemWidthPx * $fourImageFitScale));
                $displayHeightPx = (int) max(1, floor($itemHeightPx * $fourImageFitScale));
                $itemOffsetX = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_offset_x', 0, -220, 220);
                $itemOffsetY = normalizePrintSettingInt($settings, 'image_' . $imageNumber . '_offset_y', 0, -220, 220);
                $itemCellStyleParts = [];
                if ($hasCustomImageLayout) {
                    $paddingTop = $rowIndex > 0 ? (int) floor($imageGapPx / 2) : 0;
                    $paddingRight = $cellIndex === 0 ? (int) ceil($imageGapPx / 2) : 0;
                    $paddingBottom = $rowIndex < $imageRowCount - 1 ? (int) ceil($imageGapPx / 2) : 0;
                    $paddingLeft = $cellIndex === 1 ? (int) floor($imageGapPx / 2) : 0;
                    $itemCellStyleParts[] = 'padding:' . $paddingTop . 'px ' . $paddingRight . 'px ' . $paddingBottom . 'px ' . $paddingLeft . 'px';
                }
                $itemCellStyleParts[] = 'width:' . $displayWidthPx . 'px';
                $itemCellStyleParts[] = 'height:' . $displayHeightPx . 'px';
                if ($itemHasOffsetCustom) {
                    $itemCellStyleParts[] = 'transform:translate(' . $itemOffsetX . 'px,' . $itemOffsetY . 'px)';
                }
                $itemCellStyle = $itemCellStyleParts
                    ? ' style="' . implode('; ', $itemCellStyleParts) . ';"'
                    : $imageCellStyle;
                $itemImageStyle = ' style="width:' . $displayWidthPx . 'px; height:' . $displayHeightPx . 'px; max-width:none; max-height:none; object-fit:fill;"';
                $itemEditorClass = $isLayoutEditorPreview ? ' preprint-live-draggable' : '';
                $itemEditorAttributes = $isLayoutEditorPreview
                    ? ' tabindex="0" role="button" aria-label="ลากหรือปรับขนาดรูปที่ ' . $imageNumber . '" data-layout-kind="image" data-layout-label="รูปที่ ' . $imageNumber . '"'
                        . ' data-layout-x-key="image_' . $imageNumber . '_offset_x" data-layout-y-key="image_' . $imageNumber . '_offset_y"'
                        . ' data-layout-width-key="image_' . $imageNumber . '_width_px" data-layout-height-key="image_' . $imageNumber . '_height_px"'
                        . ' data-layout-x="' . $itemOffsetX . '" data-layout-y="' . $itemOffsetY . '"'
                        . ' data-layout-width="' . $itemWidthPx . '" data-layout-height="' . $itemHeightPx . '"'
                    : '';
                $imagePath = resolveLocalPath($image['file_path'] ?? '');
                $imagesHtml .= '<td class="image-shell-four' . $itemEditorClass . '"' . $itemEditorAttributes . $itemCellStyle . '>';
                if ($imagePath && file_exists($imagePath)) {
                    $imageSrc = resolveImageSrc($image['file_path'] ?? '', $imagePath, $isHtmlPreview);
                    $imagesHtml .= '<img class="image-fill" src="' . htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') . '"' . $itemImageStyle . ' />';
                } elseif ($isSettingsPreview) {
                    $imagesHtml .= '<div class="settings-preview-image-placeholder"><strong>รูปที่ ' . $imageNumber . '</strong><span>ลากเพื่อย้าย ใช้จุดมุมเพื่อปรับขนาด</span></div>';
                } else {
                    $missingPath = htmlspecialchars($image['file_path'] ?? '', ENT_QUOTES, 'UTF-8');
                    $imagesHtml .= '<div class="missing-image-note">ไม่พบไฟล์รูป<br><small>' . $missingPath . '</small></div>';
                }
                if ($isLayoutEditorPreview) {
                    $imagesHtml .= '<span class="preprint-image-resize-corner" data-layout-resize="1" aria-hidden="true"></span>';
                }
                $imagesHtml .= '</td>';
            }
            if (count($rowImages) === 1) {
                $emptyWidthPx = (int) max(1, floor($imageWidthPx * $fourImageFitScale));
                $emptyHeightPx = (int) max(1, floor($imageHeightPx * $fourImageFitScale));
                $emptyCellStyle = $hasCustomImageLayout
                    ? 'border:none; background:transparent; padding:0 0 0 ' . (int) floor($imageGapPx / 2) . 'px; width:' . $emptyWidthPx . 'px; height:' . $emptyHeightPx . 'px;'
                    : 'border:none; background:transparent;';
                $imagesHtml .= '<td class="image-shell-four" style="' . $emptyCellStyle . '"></td>';
            }
            $imagesHtml .= '</tr>';
        }
        $imagesHtml .= '</table>';
    }

    $imagesHtml .= '</div>';
    $imagesHtml .= '</div>';
}

$attendanceHtml = ($showAttendancePage && !$hideAttendance && !empty($attendanceRows))
    ? buildAttendanceSheetHtml($record, $attendanceRows, $day, $month, $year)
    : '';

$filenameParts = ['บันทึกหลังสอน'];
if (!empty($record['week'])) {
    $filenameParts[] = 'สัปดาห์ที่' . $record['week'];
}
if (!empty($record['level'])) {
    $filenameParts[] = $record['level'];
}
if (!empty($record['class_name'])) {
    $filenameParts[] = $record['class_name'];
} elseif (!empty($record['class_group_code'])) {
    $filenameParts[] = 'กลุ่ม' . $record['class_group_code'];
}
// Use descriptive filename from real record data; only use generic name for pure settings preview (no real record)
$isRealRecord = !$isSettingsPreview || ($id && $id !== 'settings-preview');
$filename = $isRealRecord
    ? implode('_', $filenameParts) . '.pdf'
    : 'ตัวอย่างตั้งค่า_PDF.pdf';

$printableHtml = $page1Html . $imagesHtml . $attendanceHtml;

if ($isHtmlPreview || $isClientPdfExport) {
    $capturedHtml = outputPrintableHtmlPreview($printableHtml, $filename);
    if (defined('SENA_PRINT_CAPTURE_HTML') && SENA_PRINT_CAPTURE_HTML) {
        return $capturedHtml;
    }
}

// All modes now use HTML output; client-side JavaScript generates PDF via html2canvas + jsPDF.
$capturedHtml = outputPrintableHtmlPreview($printableHtml, $filename);
if (defined('SENA_PRINT_CAPTURE_HTML') && SENA_PRINT_CAPTURE_HTML) {
    return $capturedHtml;
}
