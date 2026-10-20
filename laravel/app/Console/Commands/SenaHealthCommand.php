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
        $required = ['users', 'tambons', 'records', 'record_images', 'record_attendance', 'settings', 'weekly_submissions'];
        $missing = collect($required)->reject(fn ($table) => Schema::hasTable($table))->values();
        if ($missing->isNotEmpty()) {
            $this->error('Missing tables: '.$missing->join(', '));

            return self::FAILURE;
        }

        $counts = collect($required)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->table(['Table', 'Rows'], $counts->map(fn ($count, $table) => [$table, $count])->values()->all());

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
        if ($orphanImages + $orphanAttendance + $duplicates > 0) {
            $this->error('Database integrity checks failed.');

            return self::FAILURE;
        }
        $this->info('SENA Reflection health check passed.');

        return self::SUCCESS;
    }
}
