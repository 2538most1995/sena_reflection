<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

$user = checkAuth();

set_exception_handler(function (Throwable $e) {
    jsonResponse('error', 'Attendance report error: ' . $e->getMessage());
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    jsonResponse('error', 'Attendance report fatal error: ' . ($error['message'] ?? 'Unknown error'));
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse('error', 'Method not allowed');
}

$tambon = trim((string)($_GET['tambon'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$classGroup = trim((string)($_GET['class_group'] ?? ''));
$studentId = trim((string)($_GET['student_id'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$where = ["ra.student_id <> ''"];
$params = [];

if (($user['role'] ?? '') !== 'district') {
    $userTambon = trim((string) ($user['tambon'] ?? ''));
    $allowedGroups = attendanceAllowedGroupsForUser($user);
    if ($userTambon === '' && empty($allowedGroups)) {
        $where[] = "1 = 0";
    } elseif ($userTambon !== '') {
        $where[] = "r.tambon = ?";
        $params[] = $userTambon;
    } else {
        $placeholders = implode(',', array_fill(0, count($allowedGroups), '?'));
        $where[] = "(
            COALESCE(NULLIF(ra.class_name, ''), r.class_name, '') IN ($placeholders)
            OR COALESCE(NULLIF(ra.group_code, ''), r.class_group_code, '') IN ($placeholders)
        )";
        $params = array_merge($params, $allowedGroups, $allowedGroups);
    }
} elseif ($tambon !== '') {
    $where[] = "r.tambon = ?";
    $params[] = $tambon;
}

if ($level !== '') {
    $where[] = "r.level = ?";
    $params[] = $level;
}

if ($classGroup !== '') {
    $where[] = "(COALESCE(NULLIF(ra.group_code, ''), r.class_group_code, '') = ? OR COALESCE(NULLIF(ra.class_name, ''), r.class_name, '') = ?)";
    $params[] = $classGroup;
    $params[] = $classGroup;
}

if ($search !== '') {
    $where[] = "(ra.student_id LIKE ? OR ra.full_name LIKE ? OR COALESCE(NULLIF(ra.class_name, ''), r.class_name, '') LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT
        r.id AS record_id,
        r.tambon,
        t.name AS tambon_name,
        r.week,
        r.date,
        r.level,
        r.subject,
        r.subject_code,
        COALESCE(NULLIF(ra.group_code, ''), r.class_group_code, '') AS group_code,
        COALESCE(NULLIF(ra.class_name, ''), r.class_name, '') AS class_name,
        ra.student_id,
        ra.full_name,
        ra.present,
        ra.learning_method
    FROM record_attendance ra
    INNER JOIN records r ON r.id COLLATE utf8mb4_unicode_ci = ra.record_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN tambons t ON t.id COLLATE utf8mb4_unicode_ci = r.tambon COLLATE utf8mb4_unicode_ci
    $whereSql
    ORDER BY r.date ASC, r.week ASC, class_name ASC, ra.full_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = [
    'sessions' => count(array_unique(array_column($rows, 'record_id'))),
    'students' => count(array_unique(array_column($rows, 'student_id'))),
    'present' => 0,
    'absent' => 0,
    'onsite' => 0,
    'online' => 0,
    'rate' => 0,
];

$levels = [];
$classes = [];
$students = [];
$selectedTimeline = [];

foreach ($rows as $row) {
    $isPresent = (int)$row['present'] === 1;
    $method = normalizeReportLearningMethod($row['learning_method'] ?? '');
    $levelKey = $row['level'] ?: 'ไม่ระบุระดับ';
    $classKey = implode('|', [$row['group_code'] ?: '', $row['class_name'] ?: 'ไม่ระบุกลุ่ม', $row['level'] ?: '']);
    $studentKey = (string)$row['student_id'];

    if ($isPresent) {
        $summary['present']++;
        if ($method === 'ออนไลน์') {
            $summary['online']++;
        } else {
            $summary['onsite']++;
        }
    } else {
        $summary['absent']++;
    }

    if (!isset($levels[$levelKey])) {
        $levels[$levelKey] = emptyReportGroup($levelKey, $row['level'] ?: '');
    }
    updateReportGroup($levels[$levelKey], $row, $isPresent, $method);

    if (!isset($classes[$classKey])) {
        $classes[$classKey] = emptyReportGroup($row['class_name'] ?: 'ไม่ระบุกลุ่ม', $row['group_code'] ?: '', $row['level'] ?: '');
    }
    updateReportGroup($classes[$classKey], $row, $isPresent, $method);

    if (!isset($students[$studentKey])) {
        $students[$studentKey] = [
            'student_id' => $studentKey,
            'full_name' => $row['full_name'],
            'level' => $row['level'] ?: '',
            'group_code' => $row['group_code'] ?: '',
            'class_name' => $row['class_name'] ?: '',
            'tambon' => $row['tambon'],
            'tambon_name' => $row['tambon_name'] ?: '',
            'sessions' => 0,
            'present' => 0,
            'absent' => 0,
            'onsite' => 0,
            'online' => 0,
            'rate' => 0,
            'absences' => [],
        ];
    }

    $students[$studentKey]['sessions']++;
    if ($isPresent) {
        $students[$studentKey]['present']++;
        if ($method === 'ออนไลน์') {
            $students[$studentKey]['online']++;
        } else {
            $students[$studentKey]['onsite']++;
        }
    } else {
        $students[$studentKey]['absent']++;
        $students[$studentKey]['absences'][] = buildTimelineItem($row, false, $method);
    }

    if ($studentId !== '' && $studentKey === $studentId) {
        $selectedTimeline[] = buildTimelineItem($row, $isPresent, $method);
    }
}

$summary['rate'] = reportRate($summary['present'], $summary['present'] + $summary['absent']);

foreach ($levels as &$item) {
    finishReportGroup($item);
}
unset($item);

foreach ($classes as &$item) {
    finishReportGroup($item);
}
unset($item);

foreach ($students as &$student) {
    $student['rate'] = reportRate($student['present'], $student['sessions']);
}
unset($student);

$levels = array_values($levels);
$classes = array_values($classes);
$students = array_values($students);

usort($classes, static function ($a, $b) {
    return [$a['level'], $a['name']] <=> [$b['level'], $b['name']];
});
usort($students, static function ($a, $b) {
    return [$b['absent'], $a['class_name'], $a['full_name']] <=> [$a['absent'], $b['class_name'], $b['full_name']];
});

jsonResponse('success', 'fetch attendance report', [
    'summary' => $summary,
    'levels' => $levels,
    'classes' => $classes,
    'students' => $students,
    'selected_timeline' => $selectedTimeline,
]);

function emptyReportGroup($name, $code = '', $level = '') {
    return [
        'name' => $name,
        'code' => $code,
        'level' => $level,
        'sessions' => [],
        'students' => [],
        'present' => 0,
        'absent' => 0,
        'onsite' => 0,
        'online' => 0,
        'rate' => 0,
    ];
}

function updateReportGroup(&$group, array $row, bool $isPresent, string $method) {
    $group['sessions'][$row['record_id']] = true;
    $group['students'][$row['student_id']] = true;
    if ($isPresent) {
        $group['present']++;
        if ($method === 'ออนไลน์') {
            $group['online']++;
        } else {
            $group['onsite']++;
        }
    } else {
        $group['absent']++;
    }
}

function finishReportGroup(&$group) {
    $sessionCount = count($group['sessions']);
    $studentCount = count($group['students']);
    unset($group['sessions'], $group['students']);
    $group['sessions'] = $sessionCount;
    $group['students'] = $studentCount;
    $group['rate'] = reportRate($group['present'], $group['present'] + $group['absent']);
}

function buildTimelineItem(array $row, bool $isPresent, string $method) {
    return [
        'record_id' => $row['record_id'],
        'week' => (int)$row['week'],
        'date' => $row['date'],
        'subject' => $row['subject'] ?: '',
        'subject_code' => $row['subject_code'] ?: '',
        'level' => $row['level'] ?: '',
        'class_name' => $row['class_name'] ?: '',
        'present' => $isPresent,
        'learning_method' => $isPresent ? $method : '',
    ];
}

function reportRate($present, $total) {
    $total = (int)$total;
    if ($total <= 0) {
        return 0;
    }
    return round(((int)$present / $total) * 100, 1);
}

function normalizeReportLearningMethod($method) {
    $method = trim((string)$method);
    return $method === 'ออนไลน์' ? 'ออนไลน์' : 'พบกลุ่ม';
}

function attendanceAllowedGroupsForUser(array $user): array
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username === '') {
        return [];
    }

    $groups = attendanceGroupsFromStudentSystem($username);
    return array_values(array_unique(array_filter(array_map(static function ($group) {
        return trim((string)$group);
    }, $groups))));
}

function attendanceGroupsFromStudentSystem(string $username): array
{
    $host = getenv('SENA_CARE_DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
    $port = getenv('SENA_CARE_DB_PORT') ?: '3306';
    if (defined('DB_HOST') && DB_HOST === 'localhost' && !getenv('SENA_CARE_DB_PORT')) {
        $port = '8889'; // fall back to MAMP default if local
    }
    $dbname = getenv('SENA_CARE_DB_NAME') ?: 'sena_school_db';
    $dbUser = getenv('SENA_CARE_DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
    $dbPass = getenv('SENA_CARE_DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : 'root');

    try {
        $carePdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $carePdo->prepare("SELECT assigned_groups FROM users WHERE username = ? AND role = 'teacher' LIMIT 1");
        $stmt->execute([$username]);
        $assignedGroups = $stmt->fetchColumn();
        if (!$assignedGroups) {
            return [];
        }

        $decoded = json_decode((string)$assignedGroups, true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        error_log('Attendance report group lookup failed: ' . $e->getMessage());
        return [];
    }
}
