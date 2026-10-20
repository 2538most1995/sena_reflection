<?php
// config.php
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen((string) $string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string) $string, (int) $start) : substr((string) $string, (int) $start, (int) $length);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) {
        $position = strpos((string) $haystack, (string) $needle, (int) $offset);
        return $position === false ? false : $position;
    }
}

initializeSecureSession();
date_default_timezone_set('Asia/Bangkok');

define('DB_HOST', getenv('SENA_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('SENA_DB_PORT') ?: '');
define('DB_USER', getenv('SENA_DB_USER') ?: 'root');
define('DB_PASS', getenv('SENA_DB_PASS') !== false ? getenv('SENA_DB_PASS') : 'root');
define('DB_NAME', getenv('SENA_DB_NAME') ?: 'sena_reflection');
define('MAX_UPLOAD_IMAGE_BYTES', 5 * 1024 * 1024);
define('MAX_PROFILE_IMAGE_BYTES', 3 * 1024 * 1024);
define('MAX_LOGO_IMAGE_BYTES', 4 * 1024 * 1024);
// Optional absolute paths for PDF generation on production servers.
// Leave blank when node/chrome are available in the PHP process PATH.
define('SENA_NODE_BIN', '/opt/plesk/node/25/bin/node');
define('SENA_CHROME_BIN', '');

try {
    $dsn = "mysql:host=" . DB_HOST . (DB_PORT !== '' ? ";port=" . DB_PORT : '') . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Auto-migrate tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tambons` (
      `id` varchar(20) NOT NULL,
      `name` varchar(100) NOT NULL,
      `icon` varchar(20) DEFAULT '📍',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $stmt = $pdo->query("SELECT count(*) FROM tambons");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT IGNORE INTO `tambons` (`id`, `name`, `icon`) VALUES
            ('1214120001', 'เสนา', '🏙️'), ('1214120002', 'สามกอ', '🌿'),
            ('1214120003', 'เจ้าเจ็ด', '🌾'), ('1214120004', 'บ้านแพน', '🏡'),
            ('1214120005', 'บางนมโค', '🐊'), ('1214120006', 'หัวเวียง', '⛵'),
            ('1214120007', 'มารวิชัย', '🌳'), ('1214120008', 'บ้านโพธิ์', '🎆'),
            ('1214120009', 'รางจรเข้', '🥇'), ('1214120010', 'บ้านกระทุ่ม', '🏰'),
            ('1214120011', 'บ้านแถว', '🌻'), ('1214120012', 'ชายนา', '🌸'),
            ('1214120013', 'สามตุ่ม', '🏠'), ('1214120014', 'ลาดงา', '🌊'),
            ('1214120015', 'ดอนทอง', '🏺'), ('1214120016', 'บ้านหลวง', '🌾'),
            ('1214120017', 'เจ้าเสด็จ', '🍑')");
    }
    
    // Auto-migrate settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
      `setting_key` varchar(100) NOT NULL,
      `setting_value` text,
      PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $pdo->exec("ALTER TABLE `settings` MODIFY `setting_key` varchar(100) NOT NULL");
    } catch (Throwable $e) {
        // Older installs may not allow this lightweight migration; existing short keys still work.
    }
    
    $stmt = $pdo->query("SELECT count(*) FROM settings");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
            ('sig2_title', 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'),
            ('sig2_name', 'นายวุฒิพล กำมา'),
            ('sig2_pos', 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'),
            ('sig2_align', 'flex-end'),
            ('sig3_title', 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา'),
            ('sig3_name', 'นางมุจรินทร์ ชมวิชา'),
            ('sig3_pos', 'ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา'),
            ('sig3_align', 'center')");
    }

    $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
        ('sig1_align', 'flex-end'),
        ('sig1_dot_width', '180'),
        ('sig1_mt', '48'),
        ('sig1_offset', '0'),
        ('sig1_offset_y', '0'),
        ('sig1_line_offset_x', '0'),
        ('sig1_line_offset_y', '0'),
        ('sig1_name_offset_x', '0'),
        ('sig1_name_offset_y', '0'),
        ('sig1_pos_offset_x', '0'),
        ('sig1_pos_offset_y', '0'),
        ('sig1_sign_prefix_offset_x', '0'),
        ('sig1_sign_prefix_offset_y', '0'),
        ('sig1_sign_prefix', 'ลงชื่อ'),
        ('sig1_sign_suffix', 'ผู้บันทึก'),
        ('sig1_name', ''),
        ('sig1_pos', 'ครูผู้สอน'),
        ('sig2_sign_label', '(ลงชื่อ)'),
        ('sig2_dot_width', '180'),
        ('sig2_mt', '40'),
        ('sig2_offset', '0'),
        ('sig2_offset_y', '0'),
        ('sig2_line_offset_x', '0'),
        ('sig2_line_offset_y', '0'),
        ('sig2_name_offset_x', '0'),
        ('sig2_name_offset_y', '0'),
        ('sig2_pos_offset_x', '0'),
        ('sig2_pos_offset_y', '0'),
        ('sig2_sign_label_offset_x', '0'),
        ('sig2_sign_label_offset_y', '0'),
        ('sig2_block_offset_x', '0'),
        ('sig2_block_offset_y', '0'),
        ('sig2_title_offset_x', '0'),
        ('sig2_title_offset_y', '0'),
        ('sig3_sign_label', '(ลงชื่อ)'),
        ('sig3_dot_width', '200'),
        ('sig3_mt', '38'),
        ('sig3_offset', '0'),
        ('sig3_offset_y', '0'),
        ('sig3_line_offset_x', '0'),
        ('sig3_line_offset_y', '0'),
        ('sig3_name_offset_x', '0'),
        ('sig3_name_offset_y', '0'),
        ('sig3_pos_offset_x', '0'),
        ('sig3_pos_offset_y', '0'),
        ('sig3_sign_label_offset_x', '0'),
        ('sig3_sign_label_offset_y', '0'),
        ('sig3_block_offset_x', '0'),
        ('sig3_block_offset_y', '0'),
        ('sig3_title_offset_x', '0'),
        ('sig3_title_offset_y', '0'),
        ('font_size_offset', '0'),
        ('pdf_font_size_pt', '16'),
        ('pdf_line_spacing', '26'),
        ('pdf_dot_size', '2'),
        ('pdf_margin_top_px', '57'),
        ('pdf_margin_right_px', '76'),
        ('pdf_margin_bottom_px', '38'),
        ('pdf_margin_left_px', '94'),
        ('signature_font_size_pt', '16'),
        ('attendance_font_size_pt', '16'),
        ('app_logo_path', '')");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
      `id` varchar(20) NOT NULL,
      `name` varchar(100) NOT NULL,
      `username` varchar(50) NOT NULL UNIQUE,
      `password` varchar(255) NOT NULL,
      `role` enum('district','tambon') NOT NULL DEFAULT 'tambon',
      `tambon` varchar(10) DEFAULT NULL,
      `avatar_path` varchar(255) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->query("SELECT count(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT IGNORE INTO `users` (`id`, `name`, `username`, `password`, `role`, `tambon`) VALUES
            ('u0', 'ครูตำบล 0', 'admin', 'admin123', 'district', NULL),
            ('u1', 'ครูตำบล 1', '1214120001', '1214120001', 'tambon', '1214120001'),
            ('u2', 'ครูตำบล 2', '1214120002', '1214120002', 'tambon', '1214120002'),
            ('u3', 'ครูตำบล 3', '1214120003', '1214120003', 'tambon', '1214120003'),
            ('u4', 'ครูตำบล 4', '1214120004', '1214120004', 'tambon', '1214120004'),
            ('u5', 'ครูตำบล 5', '1214120005', '1214120005', 'tambon', '1214120005'),
            ('u6', 'ครูตำบล 6', '1214120006', '1214120006', 'tambon', '1214120006'),
            ('u7', 'ครูตำบล 7', '1214120007', '1214120007', 'tambon', '1214120007'),
            ('u8', 'ครูตำบล 8', '1214120008', '1214120008', 'tambon', '1214120008'),
            ('u9', 'ครูตำบล 9', '1214120009', '1214120009', 'tambon', '1214120009'),
            ('u10', 'ครูตำบล 10', '1214120010', '1214120010', 'tambon', '1214120010'),
            ('u11', 'ครูตำบล 11', '1214120011', '1214120011', 'tambon', '1214120011'),
            ('u12', 'ครูตำบล 12', '1214120012', '1214120012', 'tambon', '1214120012'),
            ('u13', 'ครูตำบล 13', '1214120013', '1214120013', 'tambon', '1214120013'),
            ('u14', 'ครูตำบล 14', '1214120014', '1214120014', 'tambon', '1214120014'),
            ('u15', 'ครูตำบล 15', '1214120015', '1214120015', 'tambon', '1214120015'),
            ('u16', 'ครูตำบล 16', '1214120016', '1214120016', 'tambon', '1214120016'),
            ('u17', 'ครูตำบล 17', '1214120017', '1214120017', 'tambon', '1214120017')");
    }
    upgradeLegacyUserPasswords($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `records` (
      `id` varchar(20) NOT NULL,
      `tambon` varchar(10) NOT NULL,
      `week` int(11) NOT NULL,
      `date` date NOT NULL,
      `level` varchar(50) DEFAULT NULL,
      `semester` varchar(10) DEFAULT '1',
      `year` varchar(10) DEFAULT '2567',
      `subject` varchar(150) NOT NULL,
      `subject_code` varchar(50) DEFAULT NULL,
      `class_group_code` varchar(50) DEFAULT NULL,
      `class_name` varchar(255) DEFAULT NULL,
      `total` int(11) DEFAULT 0,
      `present` int(11) DEFAULT 0,
      `result` text DEFAULT NULL,
      `problem` text DEFAULT NULL,
      `suggestion` text DEFAULT NULL,
      `supervisor_comment` text DEFAULT NULL,
      `created_by` varchar(20) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `record_images` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `record_id` varchar(20) NOT NULL,
      `file_path` varchar(255) NOT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_record_images_record_id` (`record_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `record_document_layouts` (
      `record_id` varchar(20) NOT NULL,
      `settings` json NOT NULL,
      `content` json NOT NULL,
      `updated_by` varchar(20) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`record_id`),
      KEY `idx_record_document_layouts_updated_by` (`updated_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensureTableCollation($pdo, 'settings');
    ensureTableCollation($pdo, 'tambons');
    ensureTableCollation($pdo, 'users');
    ensureTableCollation($pdo, 'records');
    ensureTableCollation($pdo, 'record_images');
    ensureTableCollation($pdo, 'record_document_layouts');
    ensureTableCollation($pdo, 'record_attendance');

    ensureColumn($pdo, 'records', 'class_group_code', "ALTER TABLE `records` ADD COLUMN `class_group_code` varchar(50) DEFAULT NULL AFTER `subject_code`");
    ensureColumn($pdo, 'records', 'class_name', "ALTER TABLE `records` ADD COLUMN `class_name` varchar(255) DEFAULT NULL AFTER `class_group_code`");
    ensureColumn($pdo, 'records', 'created_by', "ALTER TABLE `records` ADD COLUMN `created_by` varchar(20) DEFAULT NULL AFTER `supervisor_comment`");
    ensureColumn($pdo, 'records', 'created_at', "ALTER TABLE `records` ADD COLUMN `created_at` datetime DEFAULT CURRENT_TIMESTAMP AFTER `created_by`");
    ensureColumn($pdo, 'records', 'updated_at', "ALTER TABLE `records` ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    ensureColumn($pdo, 'users', 'avatar_path', "ALTER TABLE `users` ADD COLUMN `avatar_path` varchar(255) DEFAULT NULL AFTER `tambon`");
    ensureIndex($pdo, 'records', 'idx_records_tambon_date', "ALTER TABLE `records` ADD INDEX `idx_records_tambon_date` (`tambon`, `date`)");
    ensureIndex($pdo, 'records', 'idx_records_tambon_date_created', "ALTER TABLE `records` ADD INDEX `idx_records_tambon_date_created` (`tambon`, `date`, `created_at`)");
    ensureIndex($pdo, 'records', 'idx_records_created_by', "ALTER TABLE `records` ADD INDEX `idx_records_created_by` (`created_by`)");
    ensureIndex($pdo, 'records', 'idx_records_created_by_at', "ALTER TABLE `records` ADD INDEX `idx_records_created_by_at` (`created_by`, `created_at`)");
    ensureIndex($pdo, 'records', 'idx_records_subject', "ALTER TABLE `records` ADD INDEX `idx_records_subject` (`subject`)");
    ensureIndex($pdo, 'records', 'idx_records_subject_code', "ALTER TABLE `records` ADD INDEX `idx_records_subject_code` (`subject_code`)");
    ensureIndex($pdo, 'record_images', 'idx_record_images_record_id', "ALTER TABLE `record_images` ADD INDEX `idx_record_images_record_id` (`record_id`)");
    ensureIndex($pdo, 'users', 'idx_users_role_tambon', "ALTER TABLE `users` ADD INDEX `idx_users_role_tambon` (`role`, `tambon`)");
    ensureIndex($pdo, 'records', 'idx_records_class_group_code', "ALTER TABLE `records` ADD INDEX `idx_records_class_group_code` (`class_group_code`)");
    ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_record_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_record_student` (`record_id`, `student_id`)");
    ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_student` (`student_id`)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `record_attendance` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `record_id` varchar(20) NOT NULL,
      `student_id` varchar(50) NOT NULL,
      `full_name` varchar(255) NOT NULL,
      `group_code` varchar(50) DEFAULT NULL,
      `class_name` varchar(255) DEFAULT NULL,
      `present` tinyint(1) NOT NULL DEFAULT 0,
      `learning_method` varchar(30) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_record_attendance_record_id` (`record_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureColumn($pdo, 'record_attendance', 'learning_method', "ALTER TABLE `record_attendance` ADD COLUMN `learning_method` varchar(30) DEFAULT NULL AFTER `present`");
    ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_record_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_record_student` (`record_id`, `student_id`)");
    ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_student` (`student_id`)");
} catch (PDOException $e) {
    if ($e->getCode() == 1049) {
        // Database not found, try to create it if we have permission
        try {
            $pdo_init = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Import the sql structure
            $sql_file = __DIR__ . '/database.sql';
            if (file_exists($sql_file)) {
                $sql = file_get_contents($sql_file);
                if (!empty($sql)) {
                    $pdo->exec($sql);
                }
            }

            ensureColumn($pdo, 'records', 'class_group_code', "ALTER TABLE `records` ADD COLUMN `class_group_code` varchar(50) DEFAULT NULL AFTER `subject_code`");
            ensureColumn($pdo, 'records', 'class_name', "ALTER TABLE `records` ADD COLUMN `class_name` varchar(255) DEFAULT NULL AFTER `class_group_code`");
            ensureColumn($pdo, 'records', 'created_by', "ALTER TABLE `records` ADD COLUMN `created_by` varchar(20) DEFAULT NULL AFTER `supervisor_comment`");
            ensureColumn($pdo, 'records', 'created_at', "ALTER TABLE `records` ADD COLUMN `created_at` datetime DEFAULT CURRENT_TIMESTAMP AFTER `created_by`");
            ensureColumn($pdo, 'records', 'updated_at', "ALTER TABLE `records` ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
            ensureColumn($pdo, 'users', 'avatar_path', "ALTER TABLE `users` ADD COLUMN `avatar_path` varchar(255) DEFAULT NULL AFTER `tambon`");
            ensureIndex($pdo, 'records', 'idx_records_tambon_date', "ALTER TABLE `records` ADD INDEX `idx_records_tambon_date` (`tambon`, `date`)");
            ensureIndex($pdo, 'records', 'idx_records_tambon_date_created', "ALTER TABLE `records` ADD INDEX `idx_records_tambon_date_created` (`tambon`, `date`, `created_at`)");
            ensureIndex($pdo, 'records', 'idx_records_created_by', "ALTER TABLE `records` ADD INDEX `idx_records_created_by` (`created_by`)");
            ensureIndex($pdo, 'records', 'idx_records_created_by_at', "ALTER TABLE `records` ADD INDEX `idx_records_created_by_at` (`created_by`, `created_at`)");
            ensureIndex($pdo, 'records', 'idx_records_subject', "ALTER TABLE `records` ADD INDEX `idx_records_subject` (`subject`)");
            ensureIndex($pdo, 'records', 'idx_records_subject_code', "ALTER TABLE `records` ADD INDEX `idx_records_subject_code` (`subject_code`)");
            ensureIndex($pdo, 'record_images', 'idx_record_images_record_id', "ALTER TABLE `record_images` ADD INDEX `idx_record_images_record_id` (`record_id`)");
            ensureIndex($pdo, 'users', 'idx_users_role_tambon', "ALTER TABLE `users` ADD INDEX `idx_users_role_tambon` (`role`, `tambon`)");
            ensureIndex($pdo, 'records', 'idx_records_class_group_code', "ALTER TABLE `records` ADD INDEX `idx_records_class_group_code` (`class_group_code`)");
            ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_record_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_record_student` (`record_id`, `student_id`)");
            ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_student` (`student_id`)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `record_attendance` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `record_id` varchar(20) NOT NULL,
              `student_id` varchar(50) NOT NULL,
              `full_name` varchar(255) NOT NULL,
              `group_code` varchar(50) DEFAULT NULL,
              `class_name` varchar(255) DEFAULT NULL,
              `present` tinyint(1) NOT NULL DEFAULT 0,
              `learning_method` varchar(30) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_record_attendance_record_id` (`record_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            ensureColumn($pdo, 'record_attendance', 'learning_method', "ALTER TABLE `record_attendance` ADD COLUMN `learning_method` varchar(30) DEFAULT NULL AFTER `present`");
            ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_record_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_record_student` (`record_id`, `student_id`)");
            ensureIndex($pdo, 'record_attendance', 'idx_record_attendance_student', "ALTER TABLE `record_attendance` ADD INDEX `idx_record_attendance_student` (`student_id`)");
            upgradeLegacyUserPasswords($pdo);
        } catch (PDOException $ex) {
            die("Connection failed: " . $ex->getMessage());
        }
    } else {
        die("Connection failed: " . $e->getMessage());
    }
}

// Utility function to send JSON response
function jsonResponse($status, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonResponse('error', 'Invalid JSON payload');
    }

    return $data;
}

function normalizeInputString($value, $maxLength = 255) {
    $value = trim((string) $value);
    if ($maxLength > 0) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

function normalizeInputInt($value, $min = 0, $max = null) {
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false) {
        $number = 0;
    }
    $number = max((int) $min, (int) $number);
    if ($max !== null) {
        $number = min((int) $max, $number);
    }
    return $number;
}

function isValidDateString($value) {
    $value = (string) $value;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function getUploadDirectory() {
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }
    return $uploadDir;
}

function getUploadPublicPath($fileName) {
    return 'uploads/' . basename((string) $fileName);
}

function normalizeUploadPath($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/\?.*$/', '', $path);
    $path = ltrim($path, '/');

    $uploadsPosition = strpos($path, 'uploads/');
    if ($uploadsPosition !== false) {
        $path = substr($path, $uploadsPosition);
    }

    if (strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
        return null;
    }

    return $path;
}

function getUploadFilePath($relativePath) {
    $normalizedPath = normalizeUploadPath($relativePath);
    if ($normalizedPath === null) {
        return null;
    }

    $fileName = substr($normalizedPath, strlen('uploads/'));
    if ($fileName === '') {
        return null;
    }

    return getUploadDirectory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileName);
}

function uploadPathExists($relativePath) {
    $filePath = getUploadFilePath($relativePath);
    return $filePath !== null && is_file($filePath);
}

function deleteManagedUpload($relativePath) {
    $filePath = getUploadFilePath($relativePath);
    if ($filePath === null || !is_file($filePath)) {
        return false;
    }

    return @unlink($filePath);
}

/**
 * Store a validated profile/logo image and return an uploads-relative path.
 * This is intentionally separate from record-image handling so a user cannot
 * reuse a record upload endpoint to mutate global branding or another profile.
 */
function storeValidatedUploadedImage(array $file, array $options = []) {
    $maxBytes = (int) ($options['max_bytes'] ?? MAX_UPLOAD_IMAGE_BYTES);
    $maxWidth = (int) ($options['max_width'] ?? 2400);
    $maxHeight = (int) ($options['max_height'] ?? 2400);
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['error' => 'อัปโหลดไฟล์ไม่สำเร็จ'];
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
        return ['error' => 'ไฟล์มีขนาดใหญ่เกินกำหนด'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpName) : false;
    if ($finfo) finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimes[$mime])) {
        return ['error' => 'รองรับเฉพาะไฟล์ JPG, PNG, GIF หรือ WebP'];
    }

    $dimensions = @getimagesize($tmpName);
    if (!$dimensions || (int) ($dimensions[0] ?? 0) < 1 || (int) ($dimensions[1] ?? 0) < 1) {
        return ['error' => 'ไฟล์รูปภาพเสียหายหรืออ่านขนาดไม่ได้'];
    }
    if ((int) $dimensions[0] > $maxWidth || (int) $dimensions[1] > $maxHeight) {
        return ['error' => "รูปภาพมีขนาดเกิน {$maxWidth} x {$maxHeight} px"];
    }

    $uploadDir = getUploadDirectory();
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return ['error' => 'โฟลเดอร์ uploads ไม่สามารถเขียนไฟล์ได้'];
    }

    try {
        $fileName = bin2hex(random_bytes(18)) . '.' . $allowedMimes[$mime];
    } catch (Throwable $e) {
        $fileName = hash('sha256', uniqid('', true) . microtime(true)) . '.' . $allowedMimes[$mime];
    }
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['error' => 'ไม่สามารถบันทึกไฟล์ลงเซิร์ฟเวอร์ได้'];
    }

    return [
        'path' => getUploadPublicPath($fileName),
        'mime' => $mime,
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function initializeSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 12));

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('sena_reflection_session');
    session_start();
}

function getSessionFingerprint() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent';
    return hash('sha256', $userAgent);
}

function clearAuthenticatedSession() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function storeAuthenticatedSession(array $user) {
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user;
    $_SESSION['session_meta'] = [
        'fingerprint' => getSessionFingerprint(),
        'created_at' => time(),
        'last_activity' => time(),
    ];
}

function usersSelectColumns($includePassword = false) {
    global $pdo;
    static $hasAvatar = null;
    if ($hasAvatar === null) {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
            $hasAvatar = (bool) $columnStmt->fetch();
        } catch (Throwable $e) {
            $hasAvatar = false;
        }
    }

    $columns = 'id, name, username, role, tambon';
    if ($hasAvatar) $columns .= ', avatar_path';
    if ($includePassword) $columns .= ', password';
    return $columns;
}

function getAuthenticatedUserOrNull() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['user']) || empty($_SESSION['session_meta'])) {
        return null;
    }

    $meta = $_SESSION['session_meta'];
    $now = time();
    $idleTimeout = 60 * 60 * 2;
    $absoluteTimeout = 60 * 60 * 12;

    if (
        empty($meta['created_at']) ||
        empty($meta['last_activity']) ||
        ($now - (int) $meta['last_activity']) > $idleTimeout ||
        ($now - (int) $meta['created_at']) > $absoluteTimeout
    ) {
        clearAuthenticatedSession();
        return null;
    }

    $sessionUser = $_SESSION['user'];
    if (
        !is_array($sessionUser) ||
        empty($sessionUser['id']) ||
        !hash_equals((string) $_SESSION['user_id'], (string) $sessionUser['id'])
    ) {
        clearAuthenticatedSession();
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT " . usersSelectColumns() . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionUser['id']]);
    $freshUser = $stmt->fetch();

    if (!$freshUser) {
        clearAuthenticatedSession();
        return null;
    }

    $_SESSION['user'] = $freshUser;
    $_SESSION['user_id'] = $freshUser['id'];
    $_SESSION['session_meta']['last_activity'] = $now;
    $_SESSION['session_meta']['fingerprint'] = getSessionFingerprint();

    if (($now - (int) $meta['created_at']) > (60 * 30)) {
        session_regenerate_id(true);
        $_SESSION['session_meta']['created_at'] = $meta['created_at'];
    }

    return $freshUser;
}

// Check logged in user helper function
function checkAuth() {
    $user = getAuthenticatedUserOrNull();
    if (!$user) {
        jsonResponse('error', 'Unauthorized');
    }
    return $user;
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    $padding = strlen($data) % 4;
    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function getAppSigningKey() {
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $material = implode('|', [
        DB_HOST,
        DB_NAME,
        session_name(),
        __DIR__,
        'sena-reflection-print-token-v1',
    ]);

    $key = hash('sha256', $material, true);
    return $key;
}

function generateSignedUserToken(array $user, $scope = 'print', $ttlSeconds = 1800) {
    $payload = [
        'uid' => (string) ($user['id'] ?? ''),
        'scp' => (string) $scope,
        'exp' => time() + max(60, (int) $ttlSeconds),
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $payloadEncoded = base64UrlEncode($payloadJson);
    $signature = hash_hmac('sha256', $payloadEncoded, getAppSigningKey(), true);

    return $payloadEncoded . '.' . base64UrlEncode($signature);
}

function getAuthenticatedUserFromToken($token, $scope = 'print') {
    if (!is_string($token) || strpos($token, '.') === false) {
        return null;
    }

    [$payloadEncoded, $signatureEncoded] = explode('.', $token, 2);
    $expectedSignature = hash_hmac('sha256', $payloadEncoded, getAppSigningKey(), true);
    $providedSignature = base64UrlDecode($signatureEncoded);

    if (!is_string($providedSignature) || !hash_equals($expectedSignature, $providedSignature)) {
        return null;
    }

    $payloadJson = base64UrlDecode($payloadEncoded);
    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }

    if (($payload['scp'] ?? '') !== $scope) {
        return null;
    }

    if (empty($payload['uid']) || empty($payload['exp']) || time() > (int) $payload['exp']) {
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT " . usersSelectColumns() . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(string) $payload['uid']]);
    $freshUser = $stmt->fetch();

    return $freshUser ?: null;
}

function generateId($prefix = 'r') {
    return $prefix . uniqid();
}

function ensureTableCollation($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([(string) $table]);
        if (!$stmt->fetch()) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Optional migration: old hosting schemas may not allow table conversion.
    }
}

function ensureIndex($pdo, $table, $indexName, $ddl) {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        if (!$stmt->fetch()) {
            $pdo->exec($ddl);
        }
    } catch (PDOException $e) {
        // Optional migration: keep the app running on older partial schemas.
    }
}

function ensureColumn($pdo, $table, $columnName, $ddl) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$columnName]);
        if ($stmt->fetch()) {
            return;
        }

        try {
            $pdo->exec($ddl);
        } catch (PDOException $e) {
            $fallbackDdl = preg_replace('/\s+AFTER\s+`[^`]+`/i', '', $ddl);
            if ($fallbackDdl !== $ddl) {
                $pdo->exec($fallbackDdl);
            } else {
                throw $e;
            }
        }
    } catch (PDOException $e) {
        // Optional migration: keep the app running on older partial schemas.
    }
}

function hashPasswordIfNeeded($password) {
    if ($password === null || $password === '') {
        return $password;
    }

    $info = password_get_info($password);
    if (!empty($info['algo'])) {
        return $password;
    }

    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($plainPassword, $storedPassword) {
    if ($storedPassword === null || $storedPassword === '') {
        return false;
    }

    $info = password_get_info($storedPassword);
    if (!empty($info['algo'])) {
        return password_verify($plainPassword, $storedPassword);
    }

    return hash_equals((string) $storedPassword, (string) $plainPassword);
}

function passwordNeedsUpgrade($storedPassword) {
    $info = password_get_info($storedPassword);
    return empty($info['algo']);
}

function upgradeLegacyUserPasswords(PDO $pdo) {
    try {
        $stmt = $pdo->query("SELECT id, password FROM users");
        $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

        foreach ($stmt->fetchAll() as $user) {
            $storedPassword = (string) ($user['password'] ?? '');
            if ($storedPassword === '' || !passwordNeedsUpgrade($storedPassword)) {
                continue;
            }

            $updateStmt->execute([password_hash($storedPassword, PASSWORD_DEFAULT), $user['id']]);
        }
    } catch (Throwable $e) {
        // Optional hardening migration: login still supports legacy hashes if this fails.
    }
}

function getSig1SettingKeys() {
    return [
        'sig1_name', 'sig1_pos', 'sig1_sign_prefix', 'sig1_sign_suffix',
        'sig1_align', 'sig1_dot_width', 'sig1_mt',
        'sig1_offset', 'sig1_offset_y',
        'sig1_line_offset_x', 'sig1_line_offset_y',
        'sig1_name_offset_x', 'sig1_name_offset_y',
        'sig1_pos_offset_x', 'sig1_pos_offset_y',
        'sig1_sign_prefix_offset_x', 'sig1_sign_prefix_offset_y',
        'sig1_abs_x', 'sig1_abs_y', 'sig1_abs_w', 'sig1_abs_h',
        'sig2_offset', 'sig2_offset_y',
        'sig3_offset', 'sig3_offset_y',
        'font_size_offset', 'pdf_font_size_pt', 'pdf_line_spacing', 'pdf_dot_size',
        'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px',
        'signature_font_size_pt', 'attendance_font_size_pt',
        'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
        'image_1_width_px', 'image_1_height_px', 'image_1_offset_x', 'image_1_offset_y',
        'image_2_width_px', 'image_2_height_px', 'image_2_offset_x', 'image_2_offset_y',
        'image_3_width_px', 'image_3_height_px', 'image_3_offset_x', 'image_3_offset_y',
        'image_4_width_px', 'image_4_height_px', 'image_4_offset_x', 'image_4_offset_y'
    ];
}

function tambonScopedSettingKey($tambonId, $settingKey) {
    return 'tambon:' . $tambonId . ':' . $settingKey;
}

function applyTambonScopedOverrides(array $settings, $tambonId, array $keys) {
    if (!$tambonId) {
        return $settings;
    }

    foreach ($keys as $key) {
        $scopedKey = tambonScopedSettingKey($tambonId, $key);
        if (array_key_exists($scopedKey, $settings)) {
            $settings[$key] = $settings[$scopedKey];
        }
    }

    return $settings;
}

function hasTambonScopedSetting(array $settings, $tambonId, $settingKey) {
    if (!$tambonId || $settingKey === '') {
        return false;
    }

    return array_key_exists(tambonScopedSettingKey($tambonId, $settingKey), $settings);
}

function userScopedSettingKey($userId, $settingKey) {
    return 'user:' . $userId . ':' . $settingKey;
}

function applyUserScopedOverrides(array $settings, $userId, array $keys) {
    if (!$userId) {
        return $settings;
    }

    foreach ($keys as $key) {
        $scopedKey = userScopedSettingKey($userId, $key);
        if (array_key_exists($scopedKey, $settings)) {
            $settings[$key] = $settings[$scopedKey];
        }
    }

    return $settings;
}

function hasUserScopedSetting(array $settings, $userId, $settingKey) {
    if (!$userId || $settingKey === '') {
        return false;
    }

    return array_key_exists(userScopedSettingKey($userId, $settingKey), $settings);
}

function stripScopedSettings(array $settings) {
    foreach (array_keys($settings) as $key) {
        if (strpos($key, 'tambon:') === 0 || strpos($key, 'user:') === 0) {
            unset($settings[$key]);
        }
    }

    return $settings;
}
?>
