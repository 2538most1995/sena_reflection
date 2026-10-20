<?php

function runCodeHealthCheck($root) {
    $root = realpath($root) ?: $root;
    $checks = [];
    $add = static function ($id, $label, $status, $detail = '', $files = []) use (&$checks) {
        $checks[] = compact('id', 'label', 'status', 'detail', 'files');
    };

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $phpFiles = [];
    foreach ([$root . '/index.php', $root . '/config.php', $root . '/print.php'] as $file) {
        if (is_file($file)) $phpFiles[] = $file;
    }
    foreach (glob($root . '/api/*.php') ?: [] as $file) $phpFiles[] = $file;
    $phpErrors = [];
    foreach ($phpFiles as $file) {
        $output = [];
        $code = 0;
        @exec(escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        if ($code !== 0) $phpErrors[] = basename($file) . ': ' . trim(implode(' ', $output));
    }
    $add('php_syntax', 'ตรวจ syntax PHP', empty($phpErrors) ? 'pass' : 'fail', empty($phpErrors) ? 'PHP ทุกไฟล์หลักผ่าน' : implode("\n", $phpErrors), $phpFiles);

    $jsFile = $root . '/assets/js/app.js';
    $jsOutput = [];
    $jsCode = 0;
    if (is_file($jsFile)) {
        @exec('node --check ' . escapeshellarg($jsFile) . ' 2>&1', $jsOutput, $jsCode);
    } else {
        $jsCode = 1;
        $jsOutput[] = 'ไม่พบ assets/js/app.js';
    }
    $add('js_syntax', 'ตรวจ syntax JavaScript', $jsCode === 0 ? 'pass' : 'fail', trim(implode("\n", $jsOutput)) ?: 'JavaScript ผ่าน', [$jsFile]);

    $cssFile = $root . '/assets/css/style.css';
    $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
    $balance = substr_count($css, '{') === substr_count($css, '}');
    $add('css_balance', 'ตรวจโครงสร้าง CSS', $balance ? 'pass' : 'fail', $balance ? 'วงเล็บ CSS สมดุล' : 'จำนวนวงเล็บ CSS ไม่สมดุล', [$cssFile]);

    $phpText = '';
    foreach ($phpFiles as $file) $phpText .= "\n" . (string) @file_get_contents($file);
    $warnings = [];
    if (preg_match('/ini_set\s*\(\s*[\'\"]display_errors/i', $phpText)) $warnings[] = 'พบ display_errors ในไฟล์ PHP';
    if (strpos($phpText, 'uniqid(') !== false) $warnings[] = 'พบ uniqid() ควรใช้ random_bytes() กับไฟล์ใหม่';
    if (preg_match('/SELECT\s+\*\s+FROM\s+records/i', $phpText)) $warnings[] = 'พบ SELECT * FROM records ควรระบุ column ที่ใช้';
    $add('security_static', 'ตรวจรูปแบบเสี่ยงด้านความปลอดภัย', empty($warnings) ? 'pass' : 'warn', empty($warnings) ? 'ไม่พบ pattern เสี่ยงจากรายการตรวจ' : implode("\n", $warnings), $phpFiles);

    $htaccess = $root . '/uploads/.htaccess';
    $add('upload_hardening', 'ตรวจการป้องกันไฟล์อัปโหลด', is_file($htaccess) ? 'pass' : 'fail', is_file($htaccess) ? 'uploads มี policy ปิดการรันสคริปต์' : 'ไม่พบ uploads/.htaccess', [$htaccess]);

    $migrationText = is_file($root . '/config.php') ? (string) file_get_contents($root . '/config.php') : '';
    $migrationOk = strpos($migrationText, "'avatar_path'") !== false || strpos($migrationText, 'avatar_path') !== false;
    $add('schema_migration', 'ตรวจ migration โปรไฟล์', $migrationOk ? 'pass' : 'warn', $migrationOk ? 'พบ migration avatar_path' : 'ยังไม่พบ migration avatar_path', [$root . '/config.php']);

    $failCount = count(array_filter($checks, static function ($item) { return $item['status'] === 'fail'; }));
    $warnCount = count(array_filter($checks, static function ($item) { return $item['status'] === 'warn'; }));
    return [
        'generated_at' => date('c'),
        'summary' => [
            'status' => $failCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'pass'),
            'checks' => count($checks),
            'failures' => $failCount,
            'warnings' => $warnCount,
        ],
        'checks' => $checks,
    ];
}
