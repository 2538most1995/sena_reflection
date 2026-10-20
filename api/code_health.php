<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../tools/code_health_lib.php';

$user = checkAuth();
if ($user['role'] !== 'district') {
    jsonResponse('error', 'Unauthorized access');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse('error', 'Invalid Method');
}

jsonResponse('success', 'code health report', runCodeHealthCheck(dirname(__DIR__)));
