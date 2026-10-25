<?php

$legacy = @is_file(base_path('../config/student_api.php')) ? require base_path('../config/student_api.php') : [];
$token = env('SENA_STUDENT_API_TOKEN', $legacy['token'] ?? '');

return [
    'url' => env('SENA_STUDENT_API_URL', $token !== '' ? 'https://krumost.com/SDL_school/api/v1/integrations/student-data/students' : ($legacy['url'] ?? '')),
    'driver' => env('SENA_STUDENT_API_DRIVER', $legacy['driver'] ?? 'auto'),
    'key' => env('SENA_STUDENT_API_KEY', $legacy['key'] ?? ''),
    'token' => $token,
    'fallback_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('SENA_STUDENT_API_FALLBACK_URLS', ''))))),
];
