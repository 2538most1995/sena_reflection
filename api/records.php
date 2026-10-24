<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

$user = checkAuth();
$method = $_SERVER['REQUEST_METHOD'];

// Handle GET requests (Fetch single or all)
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $tambonFilter = $_GET['tambon'] ?? null;
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    
    // Get single record with images
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        if (!$record) jsonResponse('error', 'Not found');
        
        // Ensure authorization
        if ($user['role'] !== 'district' && $record['tambon'] !== $user['tambon']) {
            jsonResponse('error', 'Unauthorized access');
        }
        
        // Fetch images
        $imgStmt = $pdo->prepare("SELECT file_path as data, CONCAT('Image ', id) as name FROM record_images WHERE record_id = ? ORDER BY id ASC");
        $imgStmt->execute([$id]);
        $record['images'] = array_map('buildRecordImagePayload', $imgStmt->fetchAll());

        $attendanceStmt = $pdo->prepare("
            SELECT student_id, full_name, group_code, class_name, present, learning_method
            FROM record_attendance
            WHERE record_id = ?
            ORDER BY id ASC
        ");
        $attendanceStmt->execute([$id]);
        $record['attendance'] = array_map(static function ($row) {
            $row['present'] = (int) $row['present'] === 1;
            return $row;
        }, $attendanceStmt->fetchAll());
        
        jsonResponse('success', 'fetch record', $record);
    }
    
    // Fetch all records based on role
    $where = [];
    $params = [];
    if ($user['role'] !== 'district') {
        $where[] = "r.tambon = ?";
        $params[] = $user['tambon'];
        // For Tambon, ensure they only get their records
    } else if ($tambonFilter) {
        $where[] = "r.tambon = ?";
        $params[] = $tambonFilter;
    }
    
    if ($search !== '') {
        $where[] = "(r.subject LIKE ? OR r.subject_code LIKE ?)";
        $likeSearch = "%$search%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
    }
    
    $whereSql = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    // Aggregate image counts once, then join the compact result. This avoids
    // multiplying every record row before GROUP BY on large installations.
    $stmt = $pdo->prepare("
        SELECT r.*, COALESCE(image_totals.image_count, 0) AS image_count
        FROM records r
        LEFT JOIN (
            SELECT record_id, COUNT(*) AS image_count
            FROM record_images
            GROUP BY record_id
        ) image_totals ON image_totals.record_id = r.id
        $whereSql
        ORDER BY r.date DESC, r.created_at DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Format image arrays for frontend logic consistency, even if just counts
    foreach ($records as &$r) {
        if ($r['image_count'] > 0) {
            $r['images'] = array_fill(0, $r['image_count'], ['name' => 'img']); // mock array just for length check in frontend
        } else {
            $r['images'] = [];
        }
    }

    // Unique students count (not duplicated) from record_attendance
    $uniqueWhere = [];
    $uniqueParams = [];
    if ($user['role'] !== 'district') {
        $uniqueWhere[] = "r.tambon = ?";
        $uniqueParams[] = $user['tambon'];
    } else if ($tambonFilter) {
        $uniqueWhere[] = "r.tambon = ?";
        $uniqueParams[] = $tambonFilter;
    }
    $uniqueWhereSql = empty($uniqueWhere) ? "" : "WHERE " . implode(" AND ", $uniqueWhere);

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ra.student_id) AS unique_students
        FROM record_attendance ra
        INNER JOIN records r ON r.id = ra.record_id
        $uniqueWhereSql
    ");
    $stmt->execute($uniqueParams);
    $uniqueRow = $stmt->fetch();
    $uniqueStudents = (int) ($uniqueRow['unique_students'] ?? 0);

    // Monthly unique students breakdown
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(r.date, '%Y-%m') AS month_key,
            COUNT(DISTINCT ra.student_id) AS unique_count
        FROM record_attendance ra
        INNER JOIN records r ON r.id = ra.record_id
        $uniqueWhereSql
        GROUP BY month_key
        ORDER BY month_key ASC
    ");
    $stmt->execute($uniqueParams);
    $monthlyRows = $stmt->fetchAll();

    $thaiMonths = ['01'=>'ม.ค.','02'=>'ก.พ.','03'=>'มี.ค.','04'=>'เม.ย.','05'=>'พ.ค.','06'=>'มิ.ย.',
                   '07'=>'ก.ค.','08'=>'ส.ค.','09'=>'ก.ย.','10'=>'ต.ค.','11'=>'พ.ย.','12'=>'ธ.ค.'];
    $monthlyStudents = [];
    foreach ($monthlyRows as $mr) {
        $parts = explode('-', $mr['month_key']);
        $thaiYear = ((int) $parts[0]) + 543;
        $label = ($thaiMonths[$parts[1]] ?? $parts[1]) . ' ' . $thaiYear;
        $monthlyStudents[] = [
            'month' => $mr['month_key'],
            'label' => $label,
            'count' => (int) $mr['unique_count']
        ];
    }

    jsonResponse('success', 'fetch records', [
        'records' => $records,
        'uniqueStudents' => $uniqueStudents,
        'monthlyStudents' => $monthlyStudents
    ]);
}

function buildRecordImagePayload($image) {
    $path = normalizeUploadPath($image['data'] ?? '');
    $exists = $path !== null && uploadPathExists($path);

    return [
        'name' => $image['name'] ?? 'Image',
        'data' => $path ?? ($image['data'] ?? ''),
        'exists' => $exists,
        'missing' => !$exists,
    ];
}

// Helper to save base64 image
function saveBase64Image($base64Data) {
    $uploadDir = getUploadDirectory();
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return null;
    }
    
    $parts = explode(';', $base64Data);
    if(count($parts) < 2) return null; // Not valid base64 image
    
    $mimeRaw = explode('/', $parts[0])[1] ?? '';
    $mime = strtolower($mimeRaw);
    $base64Data = explode(',', $parts[1])[1] ?? '';
    
    $allowedMimes = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    if (!array_key_exists($mime, $allowedMimes)) {
        return null;
    }
    $ext = $allowedMimes[$mime];
    
    $fileData = base64_decode($base64Data);
    if ($fileData === false) return null;
    if (strlen($fileData) > MAX_UPLOAD_IMAGE_BYTES) return null;
    
    try {
        $fileName = bin2hex(random_bytes(18)) . '.' . $ext;
    } catch (Throwable $e) {
        $fileName = hash('sha256', uniqid('', true) . microtime(true)) . '.' . $ext;
    }
    $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    
    if (file_put_contents($filePath, $fileData)) {
        // Additional check just to be safe
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        if (strpos($actualMime, 'image/') !== 0) {
            unlink($filePath);
            return null;
        }
        return getUploadPublicPath($fileName); // Relative path
    }
    return null;
}

function normalizeRecordImages($images) {
    if (!is_array($images)) {
        return [];
    }

    $normalized = [];
    foreach ($images as $imgData) {
        if (is_string($imgData)) {
            $uploadPath = normalizeUploadPath($imgData);
            if ($uploadPath !== null) {
                $normalized[] = $uploadPath;
            } else if (strpos($imgData, 'data:image') === 0) {
                $normalized[] = ['data' => $imgData];
            }
        } else if (is_array($imgData) && isset($imgData['data'])) {
            $uploadPath = normalizeUploadPath($imgData['data']);
            if ($uploadPath !== null) {
                $normalized[] = $uploadPath;
            } else if (strpos($imgData['data'], 'data:image') === 0) {
                $normalized[] = $imgData;
            }
        }
    }

    return $normalized;
}

function validateRecordImages($images) {
    $normalized = normalizeRecordImages($images);
    $count = count($normalized);

    if (!in_array($count, [0, 2, 4], true)) {
        jsonResponse('error', 'ภาพประกอบการจัดการเรียนการสอนต้องอัปโหลด 2 รูป หรือ 4 รูป เท่านั้น');
    }

    return $normalized;
}

function normalizeRecordAttendance($attendance) {
    if (!is_array($attendance)) {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($attendance as $student) {
        if (!is_array($student)) {
            continue;
        }

        $studentId = trim((string)($student['student_id'] ?? $student['id'] ?? ''));
        if ($studentId === '' || isset($seen[$studentId])) {
            continue;
        }

        $fullName = trim((string)($student['full_name'] ?? $student['name'] ?? ''));
        if ($fullName === '') {
            $fullName = $studentId;
        }

        $normalized[] = [
            'student_id' => mb_substr($studentId, 0, 50),
            'full_name' => mb_substr($fullName, 0, 255),
            'group_code' => mb_substr(trim((string)($student['group_code'] ?? '')), 0, 50),
            'class_name' => mb_substr(trim((string)($student['class_name'] ?? '')), 0, 255),
            'present' => !empty($student['present']) ? 1 : 0,
            'learning_method' => normalizeLearningMethod($student['learning_method'] ?? ''),
        ];
        $seen[$studentId] = true;
    }

    return $normalized;
}

function insertRecordImages(PDO $pdo, string $recordId, array $images): void {
    if (empty($images)) {
        return;
    }

    static $requiresStringId = null;
    if ($requiresStringId === null) {
        $requiresStringId = false;
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `record_images` LIKE 'id'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($col) {
                $isAuto = strpos(strtolower($col['Extra'] ?? ''), 'auto_increment') !== false;
                $isString = preg_match('/char|text/i', $col['Type'] ?? '') === 1;
                $requiresStringId = !$isAuto && $isString;
            }
        } catch (Throwable) {
            $requiresStringId = false;
        }
    }

    $stmt = $requiresStringId
        ? $pdo->prepare("INSERT INTO record_images (id, record_id, file_path) VALUES (?, ?, ?)")
        : $pdo->prepare("INSERT INTO record_images (record_id, file_path) VALUES (?, ?)");

    foreach ($images as $imgData) {
        $path = null;
        if (is_string($imgData) && normalizeUploadPath($imgData) !== null) {
            $path = $imgData;
        } else if (is_array($imgData) && isset($imgData['data']) && strpos($imgData['data'], 'data:image') === 0) {
            $path = saveBase64Image($imgData['data']);
        }

        if ($path !== null) {
            if ($requiresStringId) {
                $imgId = 'i' . substr(str_replace('-', '', bin2hex(random_bytes(10))), 0, 19);
                $stmt->execute([$imgId, $recordId, $path]);
            } else {
                $stmt->execute([$recordId, $path]);
            }
        }
    }
}

function replaceRecordAttendance(PDO $pdo, $recordId, array $attendance) {
    $pdo->prepare("DELETE FROM record_attendance WHERE record_id = ?")->execute([$recordId]);

    if (empty($attendance)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO record_attendance (record_id, student_id, full_name, group_code, class_name, present, learning_method)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($attendance as $student) {
        $stmt->execute([
            $recordId,
            $student['student_id'],
            $student['full_name'],
            $student['group_code'],
            $student['class_name'],
            $student['present'],
            $student['present'] ? $student['learning_method'] : null,
        ]);
    }
}

function normalizeLearningMethod($method) {
    $method = trim((string)$method);
    return in_array($method, ['พบกลุ่ม', 'ออนไลน์'], true) ? $method : 'พบกลุ่ม';
}

function normalizeRecordPayload(array $data) {
    $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
    if ($week === false) {
        jsonResponse('error', 'กรุณาเลือกสัปดาห์ที่ 1-20');
    }
    $date = normalizeInputString($data['date'] ?? '', 10);
    $subject = normalizeInputString($data['subject'] ?? '', 150);

    if ($date === '' || !isValidDateString($date)) {
        jsonResponse('error', 'กรุณาระบุวันที่ให้ถูกต้อง');
    }

    if ($subject === '') {
        jsonResponse('error', 'กรุณาระบุรายวิชา');
    }

    $total = normalizeInputInt($data['total'] ?? 0, 0, 10000);
    $present = normalizeInputInt($data['present'] ?? 0, 0, $total);
    $defaultThaiAcademicYear = (int) date('Y') + 543;
    $academicYear = normalizeInputString($data['year'] ?? (string) $defaultThaiAcademicYear, 4);
    if (!preg_match('/^\d{4}$/', $academicYear) || (int) $academicYear < 2500 || (int) $academicYear > 2800) {
        jsonResponse('error', 'ปีการศึกษาต้องเป็นปี พ.ศ. ที่ถูกต้อง');
    }

    return [
        'week' => $week,
        'date' => $date,
        'level' => normalizeInputString($data['level'] ?? '', 50),
        'semester' => normalizeInputString($data['semester'] ?? '1', 10),
        'year' => $academicYear,
        'subject' => $subject,
        'subject_code' => normalizeInputString($data['subject_code'] ?? '', 50),
        'class_group_code' => normalizeInputString($data['class_group_code'] ?? '', 50),
        'class_name' => normalizeInputString($data['class_name'] ?? '', 255),
        'total' => $total,
        'present' => $present,
        'result' => normalizeInputString($data['result'] ?? '', 5000),
        'problem' => normalizeInputString($data['problem'] ?? '', 5000),
        'suggestion' => normalizeInputString($data['suggestion'] ?? '', 5000),
        'supervisor_comment' => normalizeInputString($data['supervisorComment'] ?? '', 5000),
    ];
}

// Create new record
if ($method === 'POST') {
    $data = getJsonInput();
    
    $tambon = $user['role'] === 'district'
        ? normalizeInputString($data['tambon'] ?? '', 20)
        : $user['tambon'];
    if (!$tambon) jsonResponse('error', 'ตำบล is required');
    $payload = normalizeRecordPayload($data);
    $images = validateRecordImages($data['images'] ?? []);
    $attendance = normalizeRecordAttendance($data['attendance'] ?? []);
    $total = $payload['total'];
    $present = $payload['present'];
    if (!empty($attendance)) {
        $total = count($attendance);
        $present = array_sum(array_column($attendance, 'present'));
    }
    
    $id = generateId('r');
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO records (id, tambon, week, date, level, semester, subject, subject_code, class_group_code, class_name, total, present, result, problem, suggestion, supervisor_comment, year, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id, $tambon, $payload['week'], $payload['date'], $payload['level'], $payload['semester'],
            $payload['subject'], $payload['subject_code'], $payload['class_group_code'], $payload['class_name'], $total, $present,
            $payload['result'], $payload['problem'], $payload['suggestion'], $payload['supervisor_comment'],
            $payload['year'], $user['id']
        ]);
        
        // Handle images (array of server paths or base64 backward compat)
        insertRecordImages($pdo, $id, $images);

        replaceRecordAttendance($pdo, $id, $attendance);
        
        $pdo->commit();
        jsonResponse('success', 'บันทึกข้อมูลเรียบร้อยแล้ว');
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse('error', 'บันทึกผิดพลาด: ' . $e->getMessage());
    }
}

// Update record
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = $_GET['id'] ?? $data['id'] ?? '';
    
    if (!$id) jsonResponse('error', 'Missing Record ID');
    
    // Check permission
    $stmt = $pdo->prepare("SELECT tambon FROM records WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    
    if (!$existing) jsonResponse('error', 'Record not found');
    if ($user['role'] !== 'district' && $existing['tambon'] !== $user['tambon']) {
         jsonResponse('error', 'Unauthorized');
    }
    $payload = normalizeRecordPayload($data);
    $images = validateRecordImages($data['images'] ?? []);
    $attendance = normalizeRecordAttendance($data['attendance'] ?? []);
    $total = $payload['total'];
    $present = $payload['present'];
    if (!empty($attendance)) {
        $total = count($attendance);
        $present = array_sum(array_column($attendance, 'present'));
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE records SET week=?, date=?, level=?, semester=?, subject=?, subject_code=?, class_group_code=?, class_name=?, total=?, present=?, 
            result=?, problem=?, suggestion=?, supervisor_comment=?, year=? WHERE id=?
        ");
        $stmt->execute([
            $payload['week'], $payload['date'], $payload['level'], $payload['semester'],
            $payload['subject'], $payload['subject_code'], $payload['class_group_code'], $payload['class_name'], $total, $present,
            $payload['result'], $payload['problem'], $payload['suggestion'], $payload['supervisor_comment'],
            $payload['year'], $id
        ]);
        
        // Replace images simply
        $pdo->prepare("DELETE FROM record_images WHERE record_id = ?")->execute([$id]);
        insertRecordImages($pdo, $id, $images);

        replaceRecordAttendance($pdo, $id, $attendance);
        
        $pdo->commit();
        jsonResponse('success', 'แก้ไขบันทึกเรียบร้อยแล้ว');
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse('error', 'Error updating record');
    }
}

// Delete record
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse('error', 'Missing Record ID');
    
    // Check permission
    $stmt = $pdo->prepare("SELECT tambon FROM records WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    
    if (!$existing) jsonResponse('error', 'Record not found');
    if ($user['role'] !== 'district' && $existing['tambon'] !== $user['tambon']) {
         jsonResponse('error', 'Unauthorized');
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM record_attendance WHERE record_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM record_images WHERE record_id = ?")->execute([$id]);

    $stmt = $pdo->prepare("DELETE FROM records WHERE id = ?");
    if ($stmt->execute([$id])) {
        $pdo->commit();
        jsonResponse('success', 'ลบบันทึกเรียบร้อยแล้ว');
    } else {
        $pdo->rollBack();
        jsonResponse('error', 'Error deleting record');
    }
}

jsonResponse('error', 'Invalid Method');
