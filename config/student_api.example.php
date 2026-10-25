<?php

return [
    'url' => getenv('SENA_STUDENT_API_URL') ?: 'https://krumost.com/SDL_school/api/v1/integrations/student-data/students',
    'driver' => getenv('SENA_STUDENT_API_DRIVER') ?: 'sdl',
    'token' => getenv('SENA_STUDENT_API_TOKEN') ?: '',
    'key' => getenv('SENA_STUDENT_API_KEY') ?: '',
];
