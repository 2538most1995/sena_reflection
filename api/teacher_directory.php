<?php

const DEFAULT_TEACHER_USERS_URL = 'https://krumost.com/sena_care_school/users.php';
const DEFAULT_TEACHER_STUDENT_API_URL = 'https://krumost.com/sena_care_school/api/students.php';
const DEFAULT_TEACHER_STUDENT_API_KEY = '';

function syncTeacherUsersWithLocalDatabase(PDO $pdo): array
{
    $teachers = fetchTeacherDirectoryUsers($pdo);
    if (empty($teachers)) {
        return [];
    }

    $existingStmt = $pdo->query("SELECT id, username, role FROM users");
    $existingByUsername = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existingByUsername[(string) $row['username']] = $row;
    }

    foreach ($teachers as $teacher) {
        $username = trim((string) ($teacher['username'] ?? ''));
        $name = normalizeTeacherDirectoryName($teacher['name'] ?? '');
        if ($username === '' || $name === '') {
            continue;
        }

        $role = ($teacher['role'] ?? 'tambon') === 'district' ? 'district' : 'tambon';
        $tambon = $role === 'district' ? null : trim((string) ($teacher['tambon'] ?? ''));
        $existing = $existingByUsername[$username] ?? null;
        if ($existing) {
            if (($existing['role'] ?? '') === 'district') {
                continue;
            }

            if (getenv('SENA_TEACHER_SYNC_OVERWRITE') !== '1') {
                continue;
            }

            $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, tambon = ? WHERE username = ?");
            $stmt->execute([$name, $role, $tambon, $username]);
            continue;
        }

        $stmt = $pdo->prepare("INSERT INTO users (id, name, username, password, role, tambon) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([generateId('u'), $name, $username, hashPasswordIfNeeded($username), $role, $tambon]);
    }

    return $teachers;
}

function fetchTeacherDirectoryUsers(PDO $pdo): array
{
    $usersUrl = trim((string) (getenv('SENA_TEACHER_USERS_URL') ?: DEFAULT_TEACHER_USERS_URL));
    $payload = fetchTeacherJsonUrl($usersUrl, []);
    $teachers = normalizeTeacherDirectoryPayload($payload, $pdo);
    if (!empty($teachers)) {
        return $teachers;
    }

    return fetchTeacherUsersFromStudentApi($pdo);
}

function fetchTeacherUsersFromStudentApi(PDO $pdo): array
{
    $apiUrl = trim((string) (getenv('SENA_TEACHER_STUDENT_API_URL') ?: DEFAULT_TEACHER_STUDENT_API_URL));
    $localConfigPath = dirname(__DIR__) . '/config/student_api.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    $localKey = is_array($localConfig) ? ($localConfig['key'] ?? '') : '';
    $apiKey = trim((string) (getenv('STUDENT_API_KEY') ?: getenv('SENA_STUDENT_API_KEY') ?: $localKey ?: DEFAULT_TEACHER_STUDENT_API_KEY));
    $separator = strpos($apiUrl, '?') === false ? '?' : '&';
    $url = $apiUrl . $separator . http_build_query([
        'view' => 'groups',
        'limit' => '1000',
        'status' => 'active',
        'api_key' => $apiKey,
    ]);

    $payload = fetchTeacherJsonUrl($url, $apiKey !== '' ? ['X-API-Key: ' . $apiKey] : []);
    return normalizeTeacherDirectoryPayload($payload, $pdo);
}

function fetchTeacherJsonUrl(string $url, array $headers): ?array
{
    $headers = array_merge(['Accept: application/json'], $headers);
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
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
    }

    if (!is_string($body) || trim($body) === '') {
        return null;
    }

    $payload = json_decode($body, true);
    return is_array($payload) ? $payload : null;
}

function normalizeTeacherDirectoryPayload(?array $payload, PDO $pdo): array
{
    if (!is_array($payload)) {
        return [];
    }

    $items = [];
    if (isset($payload['data']) && is_array($payload['data'])) {
        $items = $payload['data'];
    } elseif (isset($payload['users']) && is_array($payload['users'])) {
        $items = $payload['users'];
    } elseif (array_keys($payload) === range(0, count($payload) - 1)) {
        $items = $payload;
    }

    $tambons = teacherDirectoryTambons($pdo);
    $byUsername = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $nestedTeachers = $item['teachers'] ?? [];
        if (is_array($nestedTeachers)) {
            foreach ($nestedTeachers as $teacher) {
                if (!is_array($teacher)) {
                    continue;
                }
                $teacher['teacher_name'] = $teacher['teacher_name'] ?? $teacher['name'] ?? $item['teacher_name'] ?? '';
                $teacher['class_name'] = $teacher['class_name'] ?? $item['class_name'] ?? '';
                $teacher['group_code'] = $teacher['group_code'] ?? $item['group_code'] ?? '';
                addTeacherDirectoryItem($byUsername, $teacher, $tambons);
            }
        }

        addTeacherDirectoryItem($byUsername, $item, $tambons);
    }

    return array_values($byUsername);
}

function addTeacherDirectoryItem(array &$byUsername, array $item, array $tambons): void
{
    $username = trim((string) ($item['username'] ?? $item['user_name'] ?? $item['user'] ?? $item['login'] ?? ''));
    $name = normalizeTeacherDirectoryName($item['name'] ?? $item['full_name'] ?? $item['teacher_name'] ?? '');

    if ($username === '' || $name === '') {
        return;
    }

    $rawRole = strtolower(trim((string) ($item['role'] ?? $item['user_role'] ?? '')));
    $role = in_array($rawRole, ['district', 'admin', 'administrator'], true) ? 'district' : 'tambon';
    $tambon = $role === 'district' ? '' : inferTeacherTambon($item, $tambons, $username);
    if ($role === 'tambon' && $tambon === '') {
        return;
    }

    $byUsername[$username] = [
        'id' => (string) ($item['id'] ?? $username),
        'name' => $name,
        'username' => $username,
        'role' => $role,
        'tambon' => $tambon,
    ];
}

function normalizeTeacherDirectoryName($name): string
{
    $name = trim((string) $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return $name ?? '';
}

function teacherDirectoryTambons(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT id, name FROM tambons")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $tambons = [];
    foreach ($rows as $row) {
        $tambons[] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => normalizeTeacherDirectoryName($row['name'] ?? ''),
        ];
    }
    return $tambons;
}

function inferTeacherTambon(array $item, array $tambons, string $username): string
{
    foreach (['tambon', 'tambon_id', 'tambon_code', 'subdistrict_id'] as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    foreach ($tambons as $tambon) {
        if (($tambon['id'] ?? '') !== '' && (string) $tambon['id'] === $username) {
            return (string) $tambon['id'];
        }
    }

    $haystack = normalizeTeacherDirectoryComparable(implode(' ', [
        $item['class_name'] ?? '',
        $item['group_name'] ?? '',
        $item['teacher_name'] ?? '',
    ]));

    foreach ($tambons as $tambon) {
        $name = normalizeTeacherDirectoryComparable($tambon['name'] ?? '');
        if ($name !== '' && strpos($haystack, $name) !== false) {
            return (string) $tambon['id'];
        }
    }

    return '';
}

function normalizeTeacherDirectoryComparable($value): string
{
    $value = normalizeTeacherDirectoryName($value);
    $value = preg_replace('/^(ศกร\.|ระดับ)?ตำบล/u', '', $value);
    $value = str_replace(' ', '', $value);
    return strtolower($value);
}
