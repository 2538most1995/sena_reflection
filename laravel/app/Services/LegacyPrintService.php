<?php

namespace App\Services;

use App\Models\RecordRevision;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class LegacyPrintService
{
    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     * @return array{schema_version: int, record: array<string, mixed>, images: array<int, array<string, mixed>>, attendance: array<int, array<string, mixed>>, settings: array<string, mixed>, content: array<string, mixed>}
     */
    public function snapshotForRecord(
        TeachingRecord $record,
        array $settings,
        array $content,
        User $fallbackTeacher,
    ): array {
        $record->loadMissing(['tambonRelation:id,name', 'images:id,record_id,file_path', 'attendance']);
        $attributes = $record->attributesToArray();
        $attributes['date'] = $record->date?->format('Y-m-d');
        $attributes['tambon_name'] = $record->tambonRelation?->name ?? '';
        $attributes['teacher_name'] = User::query()->whereKey($record->created_by)->value('name') ?? $fallbackTeacher->name;

        return [
            'schema_version' => 1,
            'record' => $attributes,
            'images' => $record->images->map(fn ($image): array => $image->only(['id', 'file_path']))->values()->all(),
            'attendance' => $record->attendance->toArray(),
            'settings' => array_merge(
                RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->value('snapshot')['settings']
                    ?? app(RecordDocumentOptions::class)->legacySettings($record->tambon, $fallbackTeacher),
                $settings,
            ),
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function snapshotHtml(array $snapshot, User $user, bool $editor = false): string
    {
        if (! is_array($snapshot['record'] ?? null)) {
            throw new RuntimeException('ข้อมูล snapshot เอกสารไม่สมบูรณ์');
        }

        $process = new Process([
            PHP_BINARY,
            base_path('scripts/render_document_snapshot.php'),
            (string) $user->getKey(),
            $editor ? '1' : '0',
        ], null, $this->databaseEnvironment());
        $process->setInput(json_encode($snapshot, JSON_THROW_ON_ERROR));
        $process->setTimeout(30)->mustRun();

        $html = $process->getOutput();
        if (! str_contains($html, '<!doctype html>')) {
            throw new RuntimeException('สร้างเอกสารจาก snapshot ไม่สำเร็จ');
        }

        return $html;
    }

    /** @param array<string, mixed> $snapshot */
    public function snapshotPdf(array $snapshot, User $user): string
    {
        return $this->htmlToPdf($this->snapshotHtml($snapshot, $user));
    }

    public function html(TeachingRecord $record, User $user, bool $hideAttendance = false): string
    {
        $snapshot = $this->savedSnapshot($record, $user);
        if ($hideAttendance) {
            $snapshot['settings']['show_attendance_page'] = '0';
        }

        return $this->snapshotHtml($snapshot, $user);
    }

    /** @return array<string, mixed> */
    public function savedSnapshot(TeachingRecord $record, User $user): array
    {
        $record->refresh();
        $snapshot = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->value('snapshot');
        $record->load(['documentLayout', 'images', 'attendance', 'tambonRelation']);
        $options = app(RecordDocumentOptions::class);

        return $this->snapshotForRecord(
            $record,
            $options->settings(array_merge($snapshot['settings'] ?? [], $record->documentLayout?->settings ?? []), $record->images->pluck('id')->map(fn ($id): string => (string) $id)->all(), $record->tambon),
            $options->content($record),
            $user,
        );
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    public function recordDocumentHtml(
        TeachingRecord $record,
        User $user,
        array $settings,
        array $content,
        bool $editor = false,
    ): string {
        return $this->snapshotHtml($this->snapshotForRecord($record, $settings, $content, $user), $user, $editor);
    }

    public function pdf(TeachingRecord $record, User $user): string
    {
        return $this->htmlToPdf($this->html($record, $user));
    }

    /** @param array<string, string> $settings */
    public function settingsPreviewHtml(User $user, array $settings): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($settings, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/render_legacy_settings_preview.php'),
            (string) $user->getKey(),
            $encoded,
        ], null, $this->databaseEnvironment());
        $process->setTimeout(30)->mustRun();

        $html = $process->getOutput();
        if (! str_contains($html, '<!doctype html>')) {
            throw new RuntimeException('สร้างตัวอย่างเอกสารไม่สำเร็จ');
        }

        return $html;
    }

    public function combinedPdf(Collection $records, User $user): string
    {
        if ($records->isEmpty()) {
            throw new RuntimeException('ไม่พบข้อมูลสำหรับสร้าง PDF');
        }

        $directory = $this->temporaryDirectory('sena-merge-');
        $files = [];

        try {
            foreach ($records->values() as $index => $record) {
                $path = $directory.'/record-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).'.pdf';
                file_put_contents($path, $this->pdf($record, $user), LOCK_EX);
                $files[] = $path;
            }

            if (count($files) === 1) {
                return (string) file_get_contents($files[0]);
            }

            $output = $directory.'/combined.pdf';
            $python = (new ExecutableFinder)->find('python3');
            if ($python === null) {
                throw new RuntimeException('ไม่พบ Python สำหรับรวมเอกสาร PDF');
            }

            $script = <<<'PY'
import sys
from pypdf import PdfReader, PdfWriter

writer = PdfWriter()
for filename in sys.argv[2:]:
    for page in PdfReader(filename).pages:
        writer.add_page(page)
with open(sys.argv[1], "wb") as output:
    writer.write(output)
PY;
            (new Process([$python, '-c', $script, $output, ...$files]))->setTimeout(30)->mustRun();

            if (! is_file($output) || filesize($output) < 1024) {
                throw new RuntimeException('รวมเอกสาร PDF ไม่สำเร็จ');
            }

            return (string) file_get_contents($output);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function htmlToPdf(string $html): string
    {
        $chrome = $this->chromeBinary();
        $directory = $this->temporaryDirectory('sena-pdf-');
        $htmlPath = $directory.'/print.html';
        $pdfPath = $directory.'/print.pdf';
        $profilePath = $directory.'/chrome-profile';
        mkdir($profilePath, 0700, true);

        try {
            file_put_contents($htmlPath, $html, LOCK_EX);
            $process = new Process([
                $chrome,
                '--headless=new',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--allow-file-access-from-files',
                '--run-all-compositor-stages-before-draw',
                '--virtual-time-budget=1500',
                '--user-data-dir='.$profilePath,
                '--print-to-pdf='.$pdfPath,
                '--no-pdf-header-footer',
                '--print-to-pdf-no-header',
                'file://'.$htmlPath,
            ]);
            $process->setTimeout(null)->start();
            $deadline = microtime(true) + 30;
            while ($process->isRunning() && microtime(true) < $deadline) {
                if (is_file($pdfPath) && filesize($pdfPath) > 1024) {
                    usleep(500000);
                    $process->stop(1);
                    break;
                }
                usleep(100000);
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }

            if (! is_file($pdfPath) || filesize($pdfPath) < 1024) {
                throw new RuntimeException('Chrome สร้าง PDF ไม่สำเร็จ: '.mb_substr($process->getErrorOutput(), 0, 500));
            }

            return (string) file_get_contents($pdfPath);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function chromeBinary(): string
    {
        $candidates = array_filter([
            env('SENA_CHROME_BIN'),
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            (new ExecutableFinder)->find('google-chrome'),
            (new ExecutableFinder)->find('chromium'),
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('ไม่พบ Chrome/Chromium สำหรับสร้าง PDF');
    }

    private function databaseEnvironment(): array
    {
        $connection = config('database.connections.'.config('database.default'));

        return [
            'SENA_DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
            'SENA_DB_PORT' => (string) ($connection['port'] ?? ''),
            'SENA_DB_NAME' => (string) ($connection['database'] ?? ''),
            'SENA_DB_USER' => (string) ($connection['username'] ?? ''),
            'SENA_DB_PASS' => (string) ($connection['password'] ?? ''),
        ];
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('สร้างพื้นที่ชั่วคราวไม่สำเร็จ');
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
