<?php
require_once __DIR__ . '/../config.php';

$user = checkAuth();

function bundleError($message, $statusCode = 500)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function bundleSanitizeFilename($filename, $extension)
{
    $filename = trim(html_entity_decode(strip_tags((string) $filename), ENT_QUOTES, 'UTF-8'));
    $filename = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $filename);
    $filename = preg_replace('/\s+/u', ' ', $filename);
    $filename = trim($filename, " \t\n\r\0\x0B._");
    if ($filename === '') {
        $filename = 'บันทึกหลังสอน';
    }
    $extension = ltrim((string) $extension, '.');
    $filename = preg_replace('/\.[^.]+$/u', '', $filename);
    return $filename . '.' . $extension;
}

function bundleFilenameFromDisposition($disposition, $fallback, $extension)
{
    $disposition = (string) $disposition;
    if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $matches)) {
        return bundleSanitizeFilename(rawurldecode($matches[1]), $extension);
    }
    if (preg_match('/filename="?([^";]+)"?/i', $disposition, $matches)) {
        return bundleSanitizeFilename(rawurldecode($matches[1]), $extension);
    }
    return bundleSanitizeFilename($fallback, $extension);
}

function bundleUniqueFilename($filename, array &$used)
{
    $base = preg_replace('/\.[^.]+$/u', '', $filename);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $candidate = $filename;
    $index = 2;
    while (isset($used[$candidate])) {
        $candidate = $base . '_' . $index . '.' . $ext;
        $index++;
    }
    $used[$candidate] = true;
    return $candidate;
}

function bundleBaseUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/export_bundle.php'));
    $appDir = rtrim(dirname($scriptDir), '/');
    if ($appDir === '/' || $appDir === '.') {
        $appDir = '';
    }
    return $scheme . '://' . $host . $appDir;
}

function bundleFetchFile($url)
{
    $cookieName = session_name();
    $cookieValue = session_id();
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 90,
            'ignore_errors' => true,
            'header' => "Cookie: {$cookieName}={$cookieValue}\r\n",
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\\S+\\s+(\\d+)#', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    $headerMap = [];
    foreach ($headers as $header) {
        $pos = strpos($header, ':');
        if ($pos === false) continue;
        $key = strtolower(trim(substr($header, 0, $pos)));
        $headerMap[$key] = trim(substr($header, $pos + 1));
    }

    if ($status < 200 || $status >= 300 || $content === false || $content === '') {
        return [
            'ok' => false,
            'status' => $status,
            'error' => is_string($content) && $content !== '' ? mb_substr(strip_tags($content), 0, 220) : 'ดาวน์โหลดไฟล์ย่อยไม่สำเร็จ',
        ];
    }

    return [
        'ok' => true,
        'content' => $content,
        'headers' => $headerMap,
    ];
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'docx')));
if ($format === 'doc') {
    $format = 'docx';
}
if (!in_array($format, ['docx', 'pdf'], true)) {
    bundleError('ชนิดไฟล์ไม่ถูกต้อง', 400);
}
if (!class_exists('ZipArchive')) {
    bundleError('server นี้ยังไม่เปิดใช้งาน ZipArchive', 501);
}

$idsParam = trim((string) ($_GET['ids'] ?? $_POST['ids'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map(static function ($id) {
    $id = trim((string) $id);
    return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) ? $id : '';
}, explode(',', $idsParam)))));

if (count($ids) < 2) {
    bundleError('เลือกมากกว่า 1 รายการเพื่อดาวน์โหลดเป็น zip', 400);
}

$baseUrl = bundleBaseUrl();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$tmpZip = tempnam(sys_get_temp_dir(), 'sena_export_zip_');
if ($tmpZip === false) {
    bundleError('สร้างไฟล์ zip ชั่วคราวไม่สำเร็จ', 500);
}

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpZip);
    bundleError('เปิดไฟล์ zip ไม่สำเร็จ', 500);
}

$usedNames = [];
$added = 0;
$errors = [];

foreach ($ids as $index => $id) {
    $endpoint = $format === 'pdf'
        ? '/api/download_pdf.php?id=' . rawurlencode($id)
        : '/api/export_doc.php?ids=' . rawurlencode($id);
    $result = bundleFetchFile($baseUrl . $endpoint);
    if (!$result['ok']) {
        $errors[] = $id . ': ' . ($result['error'] ?? 'ไม่สำเร็จ');
        continue;
    }

    $extension = $format === 'pdf' ? 'pdf' : 'docx';
    $filename = bundleFilenameFromDisposition(
        $result['headers']['content-disposition'] ?? '',
        'บันทึกหลังสอน_' . ($index + 1) . '.' . $extension,
        $extension
    );
    $filename = bundleUniqueFilename($filename, $usedNames);
    $zip->addFromString($filename, $result['content'], ZipArchive::FL_ENC_UTF_8);
    $added++;
}

$zip->close();

if ($added === 0) {
    @unlink($tmpZip);
    bundleError('สร้าง zip ไม่สำเร็จ: ' . implode('; ', $errors), 500);
}

$zipName = 'บันทึกหลังสอน_' . ($format === 'docx' ? 'WORD_DOCX' : strtoupper($format)) . '_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipName));
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($tmpZip);
@unlink($tmpZip);
