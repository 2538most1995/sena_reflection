<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SenaHealthCommand extends Command
{
    protected $signature = 'sena:health';

    protected $description = 'Verify the Laravel/MySQL migration, record relationships, and managed image files';

    public function handle(): int
    {
        $required = ['users', 'tambons', 'records', 'record_images', 'record_attendance', 'settings', 'weekly_submissions', 'teaching_drafts', 'draft_images', 'record_revisions'];

        try {
            $missing = collect($required)->reject(fn ($table) => Schema::hasTable($table))->values();
        } catch (\Throwable $e) {
            $this->error('Database connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($missing->isNotEmpty()) {
            $this->error('Missing tables: '.$missing->join(', '));
            $this->warn('Tip: Run "php artisan migrate --force" to create missing tables.');

            return self::FAILURE;
        }

        $counts = collect($required)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->table(['Table', 'Rows'], $counts->map(fn ($count, $table) => [$table, $count])->values()->all());

        // Report ID schema for critical tables
        foreach (['record_images', 'record_attendance'] as $tableName) {
            try {
                $cols = Schema::getColumns($tableName);
                $idCol = collect($cols)->firstWhere('name', 'id');
                if ($idCol) {
                    $this->line(sprintf('Table %s.id: type=%s, auto_increment=%s', $tableName, $idCol['type_name'] ?? 'unknown', ($idCol['auto_increment'] ?? false) ? 'YES' : 'NO'));
                }
            } catch (\Throwable $e) {
                $this->warn("Could not inspect {$tableName}: ".$e->getMessage());
            }
        }

        // Check weekly_submission_id column
        if (! Schema::hasColumn('records', 'weekly_submission_id')) {
            $this->warn('Warning: column records.weekly_submission_id is missing! Run "php artisan migrate --force"');
        }

        // Check public storage link
        $storageDir = public_path('storage');
        if (! is_link($storageDir) && ! is_dir($storageDir)) {
            $this->warn('Warning: public/storage symlink is missing! Run "php artisan storage:link"');
        }

        $orphanImages = DB::table('record_images as i')->leftJoin('records as r', 'r.id', '=', 'i.record_id')->whereNull('r.id')->count();
        $orphanAttendance = DB::table('record_attendance as a')->leftJoin('records as r', 'r.id', '=', 'a.record_id')->whereNull('r.id')->count();
        $duplicates = DB::table('record_attendance')->select(['record_id', 'student_id'])->groupBy(['record_id', 'student_id'])->havingRaw('COUNT(*) > 1')->get()->count();
        $missingFiles = 0;
        DB::table('record_images')->orderBy('id')->chunk(500, function ($rows) use (&$missingFiles) {
            foreach ($rows as $row) {
                $stored = (string) $row->file_path;
                $path = str_starts_with($stored, 'storage/') ? storage_path('app/public/'.substr($stored, 8)) : dirname(base_path()).'/'.ltrim($stored, '/');
                if (! is_file($path)) {
                    $missingFiles++;
                }
            }
        });
        $this->table(['Check', 'Result'], [['orphan images', $orphanImages], ['orphan attendance', $orphanAttendance], ['duplicate attendance', $duplicates], ['missing image files', $missingFiles]]);

        // Print recent log lines if available
        $logPath = storage_path('logs/laravel.log');
        if (is_file($logPath)) {
            $lines = array_slice(file($logPath) ?: [], -25);
            if (! empty($lines)) {
                $this->newLine();
                $this->info('--- Recent Laravel Log (last 25 lines) ---');
                foreach ($lines as $line) {
                    $this->line(rtrim($line));
                }
                $this->info('--- End of Log ---');
            }
        }

        if ($orphanImages + $orphanAttendance + $duplicates > 0) {
            $this->error('Database integrity checks failed.');

            return self::FAILURE;
        }
        $this->info('SENA Reflection health check passed.');

        return self::SUCCESS;
    }
}
