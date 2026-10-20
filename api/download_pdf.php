<?php
require_once __DIR__ . '/../config.php';

function downloadPdfJsonError($message, $statusCode = 500, array $extra = [])
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'status' => 'error',
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function findChromePdfBinary()
{
    $configured = trim((string) (defined('SENA_CHROME_BIN') ? SENA_CHROME_BIN : ''));
    $candidates = [];
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    $candidates = array_merge($candidates, [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $binary) {
        $found = trim((string) @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }
    }

    return '';
}

function killChromeProfileProcesses($profilePath)
{
    $profilePath = (string) $profilePath;
    if ($profilePath === '') {
        return;
    }

    @exec('pkill -f ' . escapeshellarg($profilePath) . ' 2>/dev/null');
}

function removeDirectoryRecursive($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function extractPdfFilenameFromHtml($html, $fallback)
{
    $title = '';
    if (preg_match('/<title>(.*?)<\/title>/is', (string) $html, $matches)) {
        $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES, 'UTF-8');
    }
    $filename = trim($title !== '' ? $title : $fallback);
    $filename = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $filename);
    $filename = preg_replace('/\s+/u', ' ', $filename);
    $filename = trim($filename);
    if ($filename === '') {
        $filename = 'sena_reflection.pdf';
    }
    if (!preg_match('/\.pdf$/iu', $filename)) {
        $filename .= '.pdf';
    }
    return $filename;
}

function runChromePdfCommand(array $cmd, $timeoutSeconds = 25, $profilePath = '', $pdfPath = '')
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(implode(' ', $cmd), $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return [
            'exit_code' => 1,
            'output' => 'ไม่สามารถเปิด process Chrome ได้',
            'timed_out' => false,
        ];
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
    $deadline = time() + max(5, (int) $timeoutSeconds);
    $timedOut = false;
    $completedWithPdf = false;

    while (true) {
        $status = proc_get_status($process);
        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
        }

        if (!$status['running']) {
            break;
        }

        if ($pdfPath !== '' && is_file($pdfPath) && filesize($pdfPath) > 1024) {
            usleep(500000);
            $completedWithPdf = true;
            killChromeProfileProcesses($profilePath);
            proc_terminate($process);
            break;
        }

        if (time() >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            killChromeProfileProcesses($profilePath);
            usleep(250000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }

        usleep(100000);
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
        }
        fclose($pipe);
    }

    $exitCode = proc_close($process);
    return [
        'exit_code' => $completedWithPdf ? 0 : ($timedOut ? 124 : $exitCode),
        'output' => trim($output),
        'timed_out' => $timedOut,
        'completed_with_pdf' => $completedWithPdf,
    ];
}

function capturePrintHtmlForPdf($recordId)
{
    global $pdo;

    $_GET['id'] = (string) $recordId;
    $_GET['html_preview'] = '1';
    unset($_GET['download'], $_GET['pdf_export']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_GET['settings_preview'] = '1';
    }

    if (!defined('SENA_PRINT_CAPTURE_HTML')) {
        define('SENA_PRINT_CAPTURE_HTML', true);
    }
    if (!defined('SENA_PRINT_CAPTURE_LOCAL_ASSETS')) {
        define('SENA_PRINT_CAPTURE_LOCAL_ASSETS', true);
    }

    $html = require __DIR__ . '/../print.php';
    if (!is_string($html) || trim($html) === '') {
        downloadPdfJsonError('สร้าง HTML สำหรับ PDF ไม่สำเร็จ', 500);
    }

    return $html;
}

$user = checkAuth();
$recordId = $_GET['id'] ?? $_POST['id'] ?? '';
if ($recordId === '') {
    downloadPdfJsonError('ไม่พบรหัสบันทึก', 400);
}

$chrome = findChromePdfBinary();
if ($chrome === '') {
    downloadPdfJsonError('ไม่พบ Chrome/Chromium สำหรับสร้าง PDF ฝั่ง server', 501, [
        'fallback' => 'client',
    ]);
}

$tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sena_pdf_' . bin2hex(random_bytes(8));
$htmlPath = $tmpBase . DIRECTORY_SEPARATOR . 'print.html';
$pdfPath = $tmpBase . DIRECTORY_SEPARATOR . 'print.pdf';
$profilePath = $tmpBase . DIRECTORY_SEPARATOR . 'chrome-profile';

if (!@mkdir($tmpBase, 0700, true) || !@mkdir($profilePath, 0700, true)) {
    downloadPdfJsonError('สร้างพื้นที่ชั่วคราวสำหรับ PDF ไม่สำเร็จ', 500);
}

try {
    $html = capturePrintHtmlForPdf($recordId);
    $filename = extractPdfFilenameFromHtml($html, 'บันทึกหลังสอน.pdf');
    if (file_put_contents($htmlPath, $html) === false) {
        downloadPdfJsonError('เขียนไฟล์ HTML ชั่วคราวไม่สำเร็จ', 500);
    }

    $fileUrl = 'file://' . str_replace('%2F', '/', rawurlencode($htmlPath));
    $cmd = [
        escapeshellarg($chrome),
        '--headless=new',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--allow-file-access-from-files',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=1500',
        '--user-data-dir=' . escapeshellarg($profilePath),
        '--print-to-pdf=' . escapeshellarg($pdfPath),
        '--no-pdf-header-footer',
        '--print-to-pdf-no-header',
        escapeshellarg($fileUrl),
    ];

    $result = runChromePdfCommand($cmd, 25, $profilePath, $pdfPath);
    if ($result['exit_code'] !== 0 || !is_file($pdfPath) || filesize($pdfPath) < 1024) {
        downloadPdfJsonError('Chrome สร้าง PDF ไม่สำเร็จ', 500, [
            'fallback' => 'client',
            'detail' => $result['timed_out']
                ? 'Chrome ใช้เวลาสร้าง PDF นานเกินกำหนด'
                : implode("\n", array_slice(explode("\n", (string) $result['output']), -8)),
        ]);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($pdfPath);
} finally {
    removeDirectoryRecursive($tmpBase);
}
