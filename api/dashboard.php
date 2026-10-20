<?php
require_once __DIR__ . '/../config.php';

$user = checkAuth();
// Dashboard is specific to District admin
if ($user['role'] !== 'district') {
    jsonResponse('error', 'Unauthorized access');
}

$action = $_GET['action'] ?? 'stats';

if ($action === 'stats') {
    $rangeStart = date('Y-m-01');
    $rangeEnd = date('Y-m-01', strtotime('+1 month'));

    // Basic record stats
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN date >= ? AND date < ? THEN 1 ELSE 0 END) AS this_month,
            COUNT(DISTINCT tambon) AS tambons_done,
            COALESCE(SUM(total), 0) AS total_students_sum
        FROM records
    ");
    $stmt->execute([$rangeStart, $rangeEnd]);
    $summary = $stmt->fetch();

    // Unique students (not counting duplicates) from record_attendance
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT ra.student_id) AS unique_students
        FROM record_attendance ra
        INNER JOIN records r ON r.id = ra.record_id
    ");
    $uniqueRow = $stmt->fetch();
    $uniqueStudents = (int) ($uniqueRow['unique_students'] ?? 0);

    // Fallback: if no attendance data, use SUM(total) from records
    $totalStudentsFallback = (int) ($summary['total_students_sum'] ?? 0);
    $totalStudents = $uniqueStudents > 0 ? $uniqueStudents : $totalStudentsFallback;

    // Monthly unique students breakdown
    $stmt = $pdo->query("
        SELECT
            DATE_FORMAT(r.date, '%Y-%m') AS month_key,
            COUNT(DISTINCT ra.student_id) AS unique_count
        FROM record_attendance ra
        INNER JOIN records r ON r.id = ra.record_id
        GROUP BY month_key
        ORDER BY month_key ASC
    ");
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

    // Recent records
    $stmt = $pdo->query("
        SELECT id, tambon, subject, subject_code, class_group_code, class_name, week, date
        FROM records
        ORDER BY date DESC, created_at DESC
        LIMIT 8
    ");
    $recentRecords = $stmt->fetchAll();

    // Tambon status logic (count per tambon)
    $stmt = $pdo->query("SELECT tambon, COUNT(*) as cnt FROM records GROUP BY tambon");
    $tambonStatus = [];
    while ($row = $stmt->fetch()) {
        $tambonStatus[$row['tambon']] = $row['cnt'];
    }

    jsonResponse('success', 'fetch dashboard data', [
        'stats' => [
            'total' => (int) ($summary['total'] ?? 0),
            'thisMonth' => (int) ($summary['this_month'] ?? 0),
            'tambonsDone' => (int) ($summary['tambons_done'] ?? 0),
            'totalStudents' => $totalStudents,
            'uniqueStudents' => $uniqueStudents,
            'totalStudentsFallback' => $totalStudentsFallback
        ],
        'monthlyStudents' => $monthlyStudents,
        'recentRecords' => $recentRecords,
        'tambonStatus' => $tambonStatus
    ]);
}
