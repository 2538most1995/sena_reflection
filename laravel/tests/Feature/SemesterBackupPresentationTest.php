<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\SemesterBackupPresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class SemesterBackupPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $imageBytes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $user = User::query()->create(['id' => 'readable-admin', 'name' => 'ผู้ดูแล', 'username' => 'readable-admin', 'password' => 'fixture-only', 'role' => 'district']);
        Tambon::query()->create(['id' => 'readable-tambon', 'name' => 'เสนา']);
        $this->actingAs($user);
        $this->imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR5kAAAAASUVORK5CYII=');
        Storage::disk('public')->put('records/readable/photo.png', $this->imageBytes);
        $record = TeachingRecord::query()->create(['id' => 'readable-record', 'tambon' => 'readable-tambon', 'year' => '2569', 'semester' => '1', 'week' => 1, 'date' => '2026-05-17', 'level' => 'ประถมศึกษา',
            'subject' => '=HYPERLINK("https://example.invalid")', 'subject_code' => 'TH01', 'total' => 2, 'present' => 1,
            'result' => 'ผลการสอนภาษาไทย <script>alert("bad")</script>', 'problem' => '<img src="https://example.invalid/pixel" onerror="alert(1)">', 'suggestion' => "ติดตามผู้เรียน\nในสัปดาห์ถัดไป", 'created_by' => $user->id]);
        $record->images()->create(['file_path' => 'storage/records/readable/photo.png']);
        $record->attendance()->create(['student_id' => '00123', 'full_name' => 'นักศึกษาภาษาไทย', 'present' => true]);
        $record->attendance()->create(['student_id' => '00456', 'full_name' => '+SUM(1,2)', 'present' => false]);
    }

    public function test_v3_contains_signed_thai_offline_view_csv_and_viewable_image_extensions_without_changing_restore_data(): void
    {
        $backup = $this->createBackup();
        $this->assertSame(3, $backup['counts']['format_version']);
        $zip = $this->openZip($backup['id']);
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $data = $zip->getFromName('data.json');
            $tables = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(3, $manifest['version']);
            $this->assertSame(hash('sha256', $data), $manifest['data_sha256']);
            $this->assertCount(1, $manifest['files']);
            $this->assertSame('images/000001.png', $manifest['files'][0]['entry']);
            $this->assertSame($this->imageBytes, $zip->getFromName('images/000001.png'));
            $this->assertSame(hash('sha256', $this->imageBytes), $manifest['files'][0]['sha256']);
            $this->assertSame('storage/records/readable/photo.png', $tables['record_images'][0]['file_path']);
            $this->assertStringContainsString('<script>', $tables['records'][0]['result']);
            $this->assertSame('00123', $tables['record_attendance'][0]['student_id']);
            $this->assertCount(count(SemesterBackupPresentation::ENTRIES), $manifest['readable_files']);
            foreach ($manifest['readable_files'] as $file) {
                $contents = $zip->getFromName($file['entry']);
                $this->assertSame(strlen($contents), $file['size']);
                $this->assertSame(hash('sha256', $contents), $file['sha256']);
            }
            $html = $zip->getFromName('index.html');
            $this->assertStringContainsString('ผลการสอนภาษาไทย', $html);
            $this->assertStringContainsString('ภาพกิจกรรม 1', $html);
            $this->assertStringContainsString('src="images/000001.png"', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringContainsString('&lt;img', $html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('src="https://', $html);
            $this->assertStringContainsString('Content-Security-Policy', $html);
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            foreach ($document->getElementsByTagName('img') as $image) {
                $this->assertSame('images/000001.png', $image->getAttribute('src'));
                $this->assertFalse($image->hasAttribute('onerror'));
            }
            $this->assertStringStartsWith("\xEF\xBB\xBF", $zip->getFromName('records.csv'));
            $this->assertStringContainsString("'=HYPERLINK", $zip->getFromName('records.csv'));
            $this->assertStringContainsString("'+SUM", $zip->getFromName('attendance.csv'));
            $this->assertStringContainsString("'00123", $zip->getFromName('attendance.csv'));
            $this->assertStringContainsString('เปิด index.html', $zip->getFromName('README-TH.txt'));
        } finally {
            $zip->close();
        }
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        Storage::disk('public')->delete('records/readable/photo.png');
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk();
        $this->assertDatabaseHas('records', ['id' => 'readable-record', 'result' => 'ผลการสอนภาษาไทย <script>alert("bad")</script>']);
        $this->assertSame($this->imageBytes, Storage::disk('public')->get('records/readable/photo.png'));
    }

    #[DataProvider('invalidReadableMutations')]
    public function test_modified_missing_or_unlisted_readable_files_cannot_be_imported(string $mutation): void
    {
        $backup = $this->createBackup();
        $temporary = tempnam(sys_get_temp_dir(), 'sena-readable-');
        copy($this->zipPath($backup['id']), $temporary);
        try {
            $zip = new ZipArchive;
            $zip->open($temporary);
            match ($mutation) {
                'modified' => $zip->addFromString('index.html', '<h1>changed</h1>'),
                'missing' => $zip->deleteName('README-TH.txt'),
                'unlisted' => $zip->addFromString('files/123', $this->imageBytes),
            };
            $zip->close();
            $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('changed.zip', file_get_contents($temporary))])
                ->assertUnprocessable()->assertJsonValidationErrors('archive');
            $this->assertDatabaseCount('semester_backups', 1);
            $this->assertDatabaseHas('records', ['id' => 'readable-record']);
        } finally {
            unlink($temporary);
        }
    }

    public static function invalidReadableMutations(): array
    {
        return [['modified'], ['missing'], ['unlisted']];
    }

    public function test_pre_existing_missing_images_have_a_thai_report_without_claiming_they_are_in_the_zip(): void
    {
        Storage::disk('public')->delete('records/readable/photo.png');
        $preflight = $this->getJson(route('semester-backups.preflight', ['year' => '2569', 'semester' => '1']))->assertOk()->json();
        $backup = $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1', 'allow_missing' => true, 'preflight_fingerprint' => $preflight['fingerprint']])->assertCreated()->json('backup');
        $zip = $this->openZip($backup['id']);
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $manifest['files']);
            $this->assertCount(1, $manifest['missing_files']);
            $this->assertStringContainsString('ต้นฉบับสูญหายก่อนสำรอง 1 ไฟล์', $zip->getFromName('index.html'));
            $this->assertStringContainsString('ภาพกิจกรรม 1 สูญหายก่อนสำรอง', $zip->getFromName('index.html'));
            $this->assertStringContainsString('records/readable/photo.png', $zip->getFromName('missing-files.csv'));
            $this->assertStringNotContainsString('<img ', $zip->getFromName('index.html'));
        } finally {
            $zip->close();
        }
    }

    public function test_readable_output_is_bounded_before_an_unusable_archive_can_be_created(): void
    {
        DB::table('records')->where('id', 'readable-record')->update(['result' => str_repeat('a', SemesterBackupPresentation::MAX_BYTES + 1)]);
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertSame([], Storage::disk('local')->files('semester-backups'));
        $this->assertDatabaseHas('records', ['id' => 'readable-record']);
    }

    private function createBackup(): array
    {
        return $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertCreated()->json('backup');
    }

    private function zipPath(string $id): string
    {
        return Storage::disk('local')->path(DB::table('semester_backups')->where('id', $id)->value('storage_path'));
    }

    private function openZip(string $id): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->zipPath($id)));

        return $zip;
    }
}
