<?php

namespace App\Services;

use App\Models\ExportJob;
use App\Models\RecordRevision;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class RecordExportService
{
    public function generate(ExportJob $export, LegacyPrintService $print): void
    {
        $user = User::query()->findOrFail($export->user_id);
        $recordIds = collect($export->manifest)->pluck('record_id')->all();
        if (TeachingRecord::query()->visibleTo($user)->whereKey($recordIds)->count() !== count($recordIds)) {
            throw new RuntimeException('ไม่มีสิทธิ์เข้าถึงบันทึกบางรายการแล้ว');
        }
        $directory = 'exports/'.$user->id.'/'.$export->id;
        if (! Storage::disk('local')->makeDirectory($directory)) {
            throw new RuntimeException('สร้างโฟลเดอร์ส่งออกไม่สำเร็จ');
        }
        $files = [];
        $csv = "\xEF\xBB\xBF"."รหัสบันทึก,ฉบับ,ปี,ภาคเรียน,สัปดาห์,ระดับ,รายวิชา\n";
        foreach ($export->manifest as $entry) {
            $revision = RecordRevision::query()->findOrFail($entry['revision_id']);
            $cache = 'export-cache/'.$user->id.'/'.$revision->content_hash.'.pdf';
            if (! Storage::disk('local')->exists($cache)) {
                if (! Storage::disk('local')->put($cache, $print->snapshotPdf($revision->snapshot, $user))) {
                    throw new RuntimeException('บันทึกไฟล์ PDF ไม่สำเร็จ');
                }
            }
            $files[] = Storage::disk('local')->path($cache);
            $row = $revision->snapshot['record'];
            $csv .= implode(',', array_map(fn ($value) => $this->csvCell($value), [
                $row['id'], $revision->revision, $row['year'], $row['semester'], $row['week'], $row['level'], $row['subject'],
            ]))."\n";
            $export->increment('completed');
        }
        if ($export->filters['scope'] === 'week') {
            $relative = $directory.'/รายงานรายสัปดาห์.pdf';
            $output = Storage::disk('local')->path($relative);
            if (count($files) === 1) {
                if (! copy($files[0], $output)) {
                    throw new RuntimeException('คัดลอกไฟล์ PDF ไม่สำเร็จ');
                }
            } else {
                $python = trim((string) config('services.documents.python_binary', '')) ?: (new ExecutableFinder)->find('python3');
                if (! $python || str_contains($python, "\0")
                    || ! (str_starts_with($python, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $python))) {
                    throw new RuntimeException('ไม่พบตัวรวมไฟล์ PDF');
                }
                $script = "import sys\nfrom pypdf import PdfReader, PdfWriter\nw=PdfWriter()\nfor f in sys.argv[2:]:\n for p in PdfReader(f).pages: w.add_page(p)\nwith open(sys.argv[1], 'wb') as out: w.write(out)\n";
                (new Process([$python, '-c', $script, $output, ...$files]))->setTimeout(120)->mustRun();
            }
        } else {
            $relative = $directory.'/บันทึกหลังสอน.zip';
            $zip = new ZipArchive;
            if ($zip->open(Storage::disk('local')->path($relative), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('สร้างไฟล์ ZIP ไม่สำเร็จ');
            }
            foreach ($export->manifest as $index => $entry) {
                if (! $zip->addFile($files[$index], $entry['record_id'].'.pdf')) {
                    throw new RuntimeException('เพิ่มเอกสารลง ZIP ไม่สำเร็จ');
                }
            }
            if (! $zip->addFromString('รายการเอกสาร.csv', $csv)) {
                throw new RuntimeException('เพิ่มรายการเอกสารไม่สำเร็จ');
            }
            if (! $zip->close()) {
                throw new RuntimeException('บันทึกไฟล์ ZIP ไม่สำเร็จ');
            }
        }
        $export->update(['status' => 'ready', 'path' => $relative, 'error' => null]);
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) $value;
        if (preg_match('/^[\s]*[=+@-]/u', $text)) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }
}
