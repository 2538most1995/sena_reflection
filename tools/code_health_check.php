<?php
require_once __DIR__ . '/code_health_lib.php';

$result = runCodeHealthCheck(dirname(__DIR__));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit(($result['summary']['status'] ?? 'fail') === 'fail' ? 1 : 0);
