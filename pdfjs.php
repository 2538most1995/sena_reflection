<?php
$file = $_GET['file'] ?? 'pdf';
$allowed = [
    'pdf' => __DIR__ . '/node_modules/pdfjs-dist/build/pdf.min.mjs',
    'worker' => __DIR__ . '/node_modules/pdfjs-dist/build/pdf.worker.min.mjs',
];

if (!isset($allowed[$file]) || !is_file($allowed[$file])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

header('Content-Type: text/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($allowed[$file]);
