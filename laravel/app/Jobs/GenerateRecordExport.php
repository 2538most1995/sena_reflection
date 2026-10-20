<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\LegacyPrintService;
use App\Services\RecordExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRecordExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $exportId) {}

    /**
     * Execute the job.
     */
    public function handle(RecordExportService $exports, LegacyPrintService $print): void
    {
        $export = ExportJob::query()->find($this->exportId);
        if (! $export || $export->status !== 'queued' || $export->expires_at->isPast()) {
            return;
        }
        if (! ExportJob::query()->whereKey($export->id)->where('status', 'queued')->update(['status' => 'processing', 'completed' => 0])) {
            return;
        }
        try {
            $exports->generate($export->fresh(), $print);
        } catch (\Throwable $exception) {
            report($exception);
            $export->update(['status' => 'failed', 'error' => 'สร้างเอกสารไม่สำเร็จ ข้อมูลบันทึกยังอยู่ กรุณาลองอีกครั้ง']);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ExportJob::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'งานสร้างเอกสารถูกหยุด กรุณาลองอีกครั้ง']);
    }
}
