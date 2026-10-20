<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schedule;
use App\Models\ExportJob;

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
        if ($disk->lastModified($file) < now()->subDays(7)->timestamp) {
            $disk->delete($file);
        }
    }
    $this->info('ล้างเฉพาะไฟล์ส่งออกที่หมดอายุแล้ว ข้อมูลบันทึกและรูปต้นฉบับยังอยู่');
})->purpose('Remove expired generated exports and aged PDF cache');

Schedule::command('sena:prune-exports')->daily();
