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
        $rendererVersion = $this->rendererFingerprint();
        foreach (array_chunk($export->manifest, 25) as $entries) {
            $revisions = RecordRevision::query()->whereKey(array_column($entries, 'revision_id'))->get()->keyBy('id');
            foreach ($entries as $entry) {
                $revision = $revisions->get($entry['revision_id']);
                if (! $revision || (string) $revision->record_id !== (string) $entry['record_id']
                    || (string) ($revision->snapshot['record']['id'] ?? '') !== (string) $entry['record_id']) {
                    throw new RuntimeException('ฉบับเอกสารไม่ตรงกับรายการส่งออก');
                }
                $files[] = $this->cachedPdf($revision, $user, $print, $rendererVersion);
                $row = $revision->snapshot['record'];
                $csv .= implode(',', array_map(fn ($value) => $this->csvCell($value), [
                    $row['id'], $revision->revision, $row['year'], $row['semester'], $row['week'], $row['level'], $row['subject'],
                ]))."\n";
                $export->increment('completed');
            }
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

    public function rendererFingerprint(): string
    {
        $files = [
            public_path('build/manifest.json'),
            public_path('build/source-version.json'),
            public_path('build/fonts-manifest.json'),
            __FILE__,
            app_path('Services/LegacyPrintService.php'),
            app_path('Services/BoundDocumentRenderer.php'),
            base_path('scripts/render_document_snapshot.php'),
            dirname(base_path()).'/print.php',
        ];

        return hash('sha256', 'record-pdf-v2|'.implode('|', array_map(
            static fn (string $file): string => is_file($file) ? hash_file('sha256', $file) : 'missing',
            $files,
        )));
    }

    private function cachedPdf(RecordRevision $revision, User $user, LegacyPrintService $print, string $rendererVersion): string
    {
        $disk = Storage::disk('local');
        $directory = 'export-cache/'.$user->id;
        if (! $disk->makeDirectory($directory)) {
            throw new RuntimeException('สร้างโฟลเดอร์เก็บ PDF ไม่สำเร็จ');
        }
        $key = hash('sha256', $rendererVersion.'|'.$revision->content_hash);
        $path = $disk->path($directory.'/'.$key.'.pdf');
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('จองพื้นที่สร้าง PDF ไม่สำเร็จ');
        }
        $temporary = null;
        try {
            $deadline = microtime(true) + 45;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('PDF ฉบับเดียวกันกำลังสร้าง กรุณาลองอีกครั้ง');
                }
                usleep(50000);
            }
            if (! $this->isPdf($path)) {
                $pdf = $print->snapshotPdf($revision->snapshot, $user);
                if (! str_starts_with($pdf, '%PDF-')) {
                    throw new RuntimeException('ตัวสร้างเอกสารส่งคืนไฟล์ PDF ไม่สมบูรณ์');
                }
                $temporary = tempnam(dirname($path), '.pdf-');
                if ($temporary === false || file_put_contents($temporary, $pdf, LOCK_EX) !== strlen($pdf)
                    || ! rename($temporary, $path)) {
                    throw new RuntimeException('บันทึกไฟล์ PDF ไม่สำเร็จ');
                }
            }

            touch($path);

            return $path;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function isPdf(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return false;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            return fread($stream, 5) === '%PDF-';
        } finally {
            fclose($stream);
        }
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
