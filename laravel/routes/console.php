<?php

use App\Models\ExportJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sena:prune-exports', function (): void {
    $disk = Storage::disk('local');
    ExportJob::query()->where('expires_at', '<', now())->chunkById(50, function ($exports) use ($disk): void {
        foreach ($exports as $export) {
            $disk->deleteDirectory('exports/'.$export->user_id.'/'.$export->id);
            $export->delete();
        }
    });
    foreach ($disk->allFiles('export-cache') as $file) {
        if (str_ends_with($file, '.lock') || $disk->lastModified($file) >= now()->subDays(7)->timestamp) {
            continue;
        }
        $lock = fopen($disk->path($file).'.lock', 'c');
        if ($lock === false) {
            continue;
        }
        try {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $disk->path($file));
                if ($disk->lastModified($file) < now()->subDays(7)->timestamp) {
                    $disk->delete($file);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $this->info('ล้างเฉพาะไฟล์ส่งออกที่หมดอายุแล้ว ข้อมูลบันทึกและรูปต้นฉบับยังอยู่');
})->purpose('Remove expired generated exports and aged PDF cache');

Schedule::command('sena:prune-exports')->daily();

Schedule::command('sena:work-exports')->everyMinute()->withoutOverlapping(32);
