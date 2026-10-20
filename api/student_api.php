<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

$user = checkAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse('error', 'Method not allowed');
}

$studentApiConfig = reflectionStudentApiConfig();
$apiKey = reflectionStudentApiKey($studentApiConfig);
if ($apiKey === '') {
    jsonResponse('error', 'ยังไม่ได้ตั้งค่า Student API key สำหรับเชื่อมต่อ sena_care_school');
}

$allowedParams = [
    'page', 'limit', 'q', 'search', 'student_id', 'id', 'level',
    'class_group', 'group', 'status', 'view', 'district_id',
    'district_filter', 'include_source',
];

$query = [];
foreach ($allowedParams as $param) {
    if (isset($_GET[$param]) && $_GET[$param] !== '') {
        $query[$param] = (string) $_GET[$param];
    }
}

if (!isset($query['limit'])) {
    $query['limit'] = '1000';
}
if (!isset($query['status'])) {
    $query['status'] = 'active';
}

$httpCode = 0;
$curlError = '';
$body = null;

foreach (studentApiSourceUrls() as $sourceUrl) {
    $separator = strpos($sourceUrl, '?') === false ? '?' : '&';
    $requestQuery = $query;
    if ($apiKey !== '') {
        $requestQuery['api_key'] = $apiKey;
    }
    $url = $sourceUrl . $separator . http_build_query($requestQuery);

    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    [$body, $httpCode, $curlError] = fetchStudentApiUrl($url, $headers);

    $decodedBody = is_string($body) ? json_decode($body, true) : null;
    if ($body !== false && $body !== '' && $httpCode >= 200 && $httpCode < 300 && is_array($decodedBody) && !empty($decodedBody['success'])) {
        break;
    }
}

$decodedBody = is_string($body) ? json_decode($body, true) : null;
if ($body === false || $body === '' || $httpCode === 404 || !is_array($decodedBody) || ($httpCode >= 400 && $httpCode < 500)) {
    $body = callLocalStudentApi($query, $apiKey, $curlError);
    $httpCode = 200;
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    jsonResponse('error', 'API นักเรียนตอบกลับไม่ใช่ JSON');
}

if ($httpCode < 200 || $httpCode >= 300 || empty($payload['success'])) {
    $message = $payload['message'] ?? 'ดึงข้อมูลนักเรียนไม่สำเร็จ';
    jsonResponse('error', $message);
}

$items = filterStudentApiItemsForUser($payload['data'] ?? [], $user);

jsonResponse('success', 'fetch student api', [
    'items' => $items,
    'meta' => $payload['meta'] ?? [],
]);

function filterStudentApiItemsForUser(array $items, array $user): array
{
    if (($user['role'] ?? '') === 'district') {
        return $items;
    }

    $username = normalizeTeacherMatchValue($user['username'] ?? '');
    $name = normalizeTeacherMatchValue($user['name'] ?? '');
    $tambonName = normalizeTeacherMatchValue(resolveUserTambonName($user['tambon'] ?? ''));
    if ($username === '' && $name === '' && $tambonName === '') {
        return [];
    }

    return array_values(array_filter($items, static function ($item) use ($username, $name, $tambonName) {
        $teachers = $item['teachers'] ?? [];
        if (!is_array($teachers)) {
            $teachers = [];
        }

        foreach ($teachers as $teacher) {
            if (!is_array($teacher)) {
                continue;
            }

            $teacherUsername = normalizeTeacherMatchValue($teacher['username'] ?? '');
            $teacherName = normalizeTeacherMatchValue($teacher['name'] ?? '');
            if ($username !== '' && $teacherUsername !== '' && hash_equals($username, $teacherUsername)) {
                return true;
            }
            if ($name !== '' && $teacherName !== '' && hash_equals($name, $teacherName)) {
                return true;
            }
        }

        $className = normalizeTeacherMatchValue($item['class_name'] ?? '');
        if (empty($teachers) && $tambonName !== '' && $className !== '' && mb_strpos($className, $tambonName) !== false) {
            return true;
        }

        return false;
    }));
}

function normalizeTeacherMatchValue($value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return $value ?? '';
}

function resolveUserTambonName($tambonId): string
{
    $tambonId = trim((string) $tambonId);
    if ($tambonId === '') {
        return '';
    }

    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT name FROM tambons WHERE id = ? LIMIT 1");
        $stmt->execute([$tambonId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function fetchStudentApiUrl(string $url, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$body, $httpCode, $error];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match)) {
        $httpCode = (int) $match[1];
    }

    return [$body, $httpCode, $body === false ? 'file_get_contents failed' : ''];
}

function studentApiSourceUrls(): array
{
    global $studentApiConfig;

    $configured = trim((string) (getenv('SENA_STUDENT_API_URL') ?: ''));
    if ($configured !== '') {
        return [$configured];
    }

    $configUrl = trim((string) ($studentApiConfig['url'] ?? ''));
    if ($configUrl !== '') {
        return [$configUrl];
    }

    $urls = [];
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalHost = $host === ''
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || strpos($host, '[::1]') !== false;

    $urls[] = 'https://krumost.com/sena_care_school/api/students.php';

    if (!$isLocalHost) {
        $baseUrl = studentApiPublicBaseUrl();
        if ($baseUrl !== '') {
            foreach ([
                '/sena_care_school/api/students.php',
                '/sena_care_school%203/api/students.php',
                '/sena_school/api/students.php',
                '/sena_school%202/api/students.php',
            ] as $path) {
                $urls[] = $baseUrl . $path;
            }
        }
    }

    if ($isLocalHost) {
        $urls[] = 'http://localhost:8888/sena_care_school%203/api/students.php';
        $urls[] = 'http://localhost:8888/sena_care_school/api/students.php';
    }

    return array_values(array_unique($urls));
}

function reflectionStudentApiConfig(): array
{
    $configFile = __DIR__ . '/../config/student_api.php';
    if (!is_file($configFile)) {
        return [];
    }

    $config = require $configFile;
    return is_array($config) ? $config : [];
}

function reflectionStudentApiKey(array $config): string
{
    $candidates = [
        getenv('STUDENT_API_KEY') ?: '',
        getenv('REDIRECT_STUDENT_API_KEY') ?: '',
        getenv('SENA_STUDENT_API_KEY') ?: '',
        getenv('REDIRECT_SENA_STUDENT_API_KEY') ?: '',
        $_SERVER['STUDENT_API_KEY'] ?? '',
        $_SERVER['REDIRECT_STUDENT_API_KEY'] ?? '',
        $_SERVER['SENA_STUDENT_API_KEY'] ?? '',
        $_SERVER['REDIRECT_SENA_STUDENT_API_KEY'] ?? '',
        $config['key'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $multiKeySources = [
        getenv('STUDENT_API_KEYS') ?: '',
        getenv('REDIRECT_STUDENT_API_KEYS') ?: '',
        $_SERVER['STUDENT_API_KEYS'] ?? '',
        $_SERVER['REDIRECT_STUDENT_API_KEYS'] ?? '',
        is_array($config['keys'] ?? null) ? implode(',', $config['keys']) : ($config['keys'] ?? ''),
    ];
    foreach ($multiKeySources as $source) {
        $keys = array_values(array_filter(array_map('trim', explode(',', (string) $source))));
        if (!empty($keys)) {
            return $keys[0];
        }
    }

    return '';
}

function studentApiPublicBaseUrl(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    return ($isHttps ? 'https://' : 'http://') . $host;
}

function callLocalStudentApi(array $query, string $apiKey, string $previousError = ''): string
{
    if (!function_exists('proc_open')) {
        jsonResponse('error', 'ไม่สามารถเรียก API นักเรียนในเครื่องได้บนโฮสต์นี้' . ($previousError ? ': ' . $previousError : ''));
    }

    $apiFile = trim((string) (getenv('SENA_STUDENT_API_FILE') ?: ''));
    if ($apiFile === '') {
        $htdocsDir = dirname(__DIR__, 2);
        $candidates = [
            $htdocsDir . DIRECTORY_SEPARATOR . 'sena_care_school 3' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'students.php',
            $htdocsDir . DIRECTORY_SEPARATOR . 'sena_care_school' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'students.php',
            $htdocsDir . DIRECTORY_SEPARATOR . 'sena_school' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'students.php',
            $htdocsDir . DIRECTORY_SEPARATOR . 'sena_school 2' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'students.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $apiFile = $candidate;
                break;
            }
        }
    }

    if (!is_file($apiFile)) {
        jsonResponse('error', 'ไม่พบไฟล์ API นักเรียน' . ($previousError ? ': ' . $previousError : ''));
    }

    $phpBinary = trim((string) (getenv('SENA_STUDENT_API_PHP') ?: ''));
    if ($phpBinary === '') {
        $candidates = [
            '/Applications/MAMP/bin/php/php8.3.14/bin/php',
            '/Applications/MAMP/bin/php/php8.4.1/bin/php',
            PHP_BINARY,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate) && is_executable($candidate)) {
                $phpBinary = $candidate;
                break;
            }
        }
    }

    if ($phpBinary === '') {
        jsonResponse('error', 'ไม่พบ PHP binary สำหรับเรียก API นักเรียน');
    }

    $bootstrap = <<<'PHP'
$apiFile = getenv('SENA_PROXY_STUDENT_API_FILE');
$query = json_decode(getenv('SENA_PROXY_STUDENT_QUERY') ?: '{}', true);
if (!is_array($query)) $query = [];
$_GET = $query;
$_REQUEST = $query;
$_SERVER['REQUEST_METHOD'] = 'GET';
$apiKey = getenv('SENA_PROXY_STUDENT_API_KEY') ?: '';
if ($apiKey !== '') {
    putenv('STUDENT_API_KEY=' . $apiKey);
    $_SERVER['HTTP_X_API_KEY'] = $apiKey;
}
chdir(dirname(dirname($apiFile)));
require $apiFile;
PHP;

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'STUDENT_API_KEY' => $apiKey,
        'SENA_PROXY_STUDENT_API_FILE' => $apiFile,
        'SENA_PROXY_STUDENT_QUERY' => json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'SENA_PROXY_STUDENT_API_KEY' => $apiKey,
    ]);

    $process = proc_open([$phpBinary, '-d', 'display_errors=0', '-r', $bootstrap], $descriptorSpec, $pipes, dirname($apiFile), $env);
    if (!is_resource($process)) {
        jsonResponse('error', 'ไม่สามารถเริ่ม subprocess สำหรับ API นักเรียนได้');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || trim((string) $output) === '') {
        $message = trim((string) $errorOutput);
        jsonResponse('error', 'เรียก API นักเรียนผ่านไฟล์ไม่สำเร็จ' . ($message ? ': ' . $message : ''));
    }

    return $output;
}
