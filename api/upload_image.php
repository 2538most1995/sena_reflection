<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

$user = checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
        if ((int) ($_FILES['image']['size'] ?? 0) > MAX_UPLOAD_IMAGE_BYTES) {
            jsonResponse('error', 'ไฟล์รูปภาพมีขนาดใหญ่เกิน 5MB');
        }

        $uploadDir = getUploadDirectory();
        if (!is_dir($uploadDir)) {
            jsonResponse('error', 'ไม่พบโฟลเดอร์ uploads และไม่สามารถสร้างได้');
        }
        if (!is_writable($uploadDir)) {
            jsonResponse('error', 'โฟลเดอร์ uploads ไม่สามารถเขียนไฟล์ได้ กรุณาตั้ง permission ให้ server เขียนได้');
        }
        
        $tmpName = $_FILES['image']['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            jsonResponse('error', 'ไฟล์อัปโหลดไม่ถูกต้อง');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedMimes[$mime])) {
            jsonResponse('error', 'ไฟล์ไม่ใช่รูปภาพที่ถูกต้อง');
        }
        $dimensions = @getimagesize($tmpName);
        if ($dimensions === false || (int) ($dimensions[0] ?? 0) > 5000 || (int) ($dimensions[1] ?? 0) > 5000) {
            jsonResponse('error', 'ไฟล์รูปภาพเสียหายหรือไม่สามารถอ่านได้');
        }
        $ext = $allowedMimes[$mime];

        try {
            $fileName = bin2hex(random_bytes(18)) . '.' . $ext;
        } catch (Throwable $e) {
            $fileName = hash('sha256', uniqid('', true) . microtime(true)) . '.' . $ext;
        }
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        
        if (move_uploaded_file($tmpName, $targetPath)) {
            jsonResponse('success', 'uploaded', ['path' => getUploadPublicPath($fileName)]);
        }
    }
    jsonResponse('error', 'อัปโหลดรูปภาพไม่สำเร็จ กรุณาตรวจสอบ permission และขนาดไฟล์บน server');
}

jsonResponse('error', 'Invalid request');
