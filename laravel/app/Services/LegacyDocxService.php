<?php

namespace App\Services;

use App\Models\TeachingRecord;
use App\Models\User;
use RuntimeException;
use Symfony\Component\Process\Process;

class LegacyDocxService
{
    public function render(TeachingRecord $record, User $user): string
    {
        $connection = config('database.connections.'.config('database.default'));
        $process = new Process([
            PHP_BINARY, base_path('scripts/render_legacy_docx.php'), (string) $record->id, (string) $user->id,
        ], null, [
            'SENA_DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
            'SENA_DB_PORT' => (string) ($connection['port'] ?? ''),
            'SENA_DB_NAME' => (string) ($connection['database'] ?? ''),
            'SENA_DB_USER' => (string) ($connection['username'] ?? ''),
            'SENA_DB_PASS' => (string) ($connection['password'] ?? ''),
        ]);
        $process->setTimeout(30)->mustRun();
        $content = $process->getOutput();
        if (! str_starts_with($content, 'PK') || strlen($content) < 1024) {
            throw new RuntimeException('สร้างไฟล์ Word รูปแบบเดิมไม่สำเร็จ');
        }

        return $content;
    }
}
