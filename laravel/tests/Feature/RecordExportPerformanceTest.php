<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use App\Services\RecordExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class RecordExportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $originalPublicPath;

    private string $buildDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->originalPublicPath = public_path();
        $this->buildDirectory = sys_get_temp_dir().'/sena-export-build-'.Str::uuid();
        File::ensureDirectoryExists($this->buildDirectory.'/build');
        File::put($this->buildDirectory.'/build/source-version.json', '{"sourceHash":"first-build"}');
        app()->usePublicPath($this->buildDirectory);
    }

    protected function tearDown(): void
    {
        app()->usePublicPath($this->originalPublicPath);
        File::deleteDirectory($this->buildDirectory);
        parent::tearDown();
    }

    public function test_revisions_are_loaded_in_bounded_batches_and_cached_without_changing_manifest_order(): void
    {
        [$user, $manifest] = $this->fixtures(27);
        $manifest = array_reverse($manifest);
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->times(27)->withArgs(function (array $snapshot, User $teacher) use ($user): bool {
            return $teacher->id === $user->id && $snapshot['record']['result'] === 'frozen text';
        })->andReturnUsing(fn (array $snapshot): string => '%PDF-'.$snapshot['record']['id']);
        TeachingRecord::query()->update(['result' => 'new text after request']);
        DB::enableQueryLog();
        app(RecordExportService::class)->generate($first = $this->export($user, $manifest), $print);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'record_revisions'));
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertSame(27, $first->fresh()->completed);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($first->fresh()->path)));
        $this->assertSame($manifest[0]['record_id'].'.pdf', $zip->getNameIndex(0));
        $this->assertSame('%PDF-'.$manifest[0]['record_id'], $zip->getFromIndex(0));
        $zip->close();
        app(RecordExportService::class)->generate($second = $this->export($user, $manifest), $print);
        $this->assertSame('ready', $second->fresh()->status);
        $this->assertCount(27, $this->cachedPdfs());
    }

    public function test_renderer_build_change_invalidates_pdf_cache(): void
    {
        [$user, $manifest] = $this->fixtures(1);
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->twice()->andReturn('%PDF-first', '%PDF-second');
        app(RecordExportService::class)->generate($this->export($user, $manifest), $print);
        File::put($this->buildDirectory.'/build/source-version.json', '{"sourceHash":"second-build"}');
        app(RecordExportService::class)->generate($second = $this->export($user, $manifest), $print);
        $this->assertCount(2, $this->cachedPdfs());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($second->fresh()->path)));
        $this->assertSame('%PDF-second', $zip->getFromIndex(0));
        $zip->close();
    }

    public function test_failed_render_does_not_publish_partial_cache_and_retry_releases_the_lock(): void
    {
        [$user, $manifest] = $this->fixtures(1);
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->andReturnUsing(function (): string {
            $this->assertSame([], $this->cachedPdfs());

            return 'partial invalid response';
        });
        try {
            app(RecordExportService::class)->generate($this->export($user, $manifest), $print);
            $this->fail('An invalid PDF must never be published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('PDF', $exception->getMessage());
        }
        $this->assertSame([], $this->cachedPdfs());
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles('export-cache'), fn (string $path): bool => str_contains($path, '/.pdf-'))));
        $retryPrint = $this->mock(LegacyPrintService::class);
        $retryPrint->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-retry');
        app(RecordExportService::class)->generate($retry = $this->export($user, $manifest), $retryPrint);
        $this->assertSame('ready', $retry->fresh()->status);
        $this->assertCount(1, $this->cachedPdfs());
    }

    public function test_revision_from_another_record_is_rejected_before_render_or_cache_use(): void
    {
        [$user, $manifest] = $this->fixtures(2);
        $manifest[0]['revision_id'] = $manifest[1]['revision_id'];
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldNotReceive('snapshotPdf');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ฉบับเอกสารไม่ตรงกับรายการส่งออก');
        app(RecordExportService::class)->generate($this->export($user, $manifest), $print);
    }

    public function test_invalid_existing_cache_is_replaced_only_after_a_successful_render(): void
    {
        [$user, $manifest] = $this->fixtures(1);
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-original');
        app(RecordExportService::class)->generate($this->export($user, $manifest), $print);
        $cache = $this->cachedPdfs()[0];
        Storage::disk('local')->put($cache, 'broken cache');
        $retryPrint = $this->mock(LegacyPrintService::class);
        $retryPrint->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-rebuilt');
        app(RecordExportService::class)->generate($this->export($user, $manifest), $retryPrint);
        $this->assertSame('%PDF-rebuilt', Storage::disk('local')->get($cache));
        $this->assertSame([$cache], $this->cachedPdfs());
    }

    /** @return array{User, list<array{record_id: string, revision_id: string}>} */
    private function fixtures(int $count): array
    {
        Tambon::create(['id' => 'export-scope', 'name' => 'ตำบลทดสอบ']);
        $user = User::create(['id' => 'export-teacher', 'username' => 'export-teacher', 'name' => 'ครู', 'role' => 'tambon', 'tambon' => 'export-scope', 'password' => Hash::make('test-password')]);
        $manifest = [];
        for ($index = 0; $index < $count; $index++) {
            $record = TeachingRecord::create([
                'id' => 'performance-'.$index, 'tambon' => $user->tambon, 'created_by' => $user->id,
                'year' => '2569', 'semester' => '1', 'week' => 8, 'date' => '2026-10-16',
                'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'result' => 'frozen text', 'total' => 3, 'present' => 2,
            ]);
            $snapshot = ['record' => $record->attributesToArray(), 'settings' => [], 'images' => [], 'attendance' => []];
            $revision = RecordRevision::create([
                'id' => (string) Str::uuid(), 'record_id' => $record->id, 'revision' => 1,
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'snapshot' => $snapshot, 'created_by' => $user->id, 'created_at' => now(),
            ]);
            $manifest[] = ['record_id' => $record->id, 'revision_id' => $revision->id];
        }

        return [$user, $manifest];
    }

    /** @param list<array{record_id: string, revision_id: string}> $manifest */
    private function export(User $user, array $manifest): ExportJob
    {
        return ExportJob::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'processing',
            'filters' => ['scope' => 'all'], 'manifest' => $manifest, 'total' => count($manifest),
            'expires_at' => now()->addDay(),
        ]);
    }

    /** @return list<string> */
    private function cachedPdfs(): array
    {
        return array_values(array_filter(Storage::disk('local')->allFiles('export-cache'), fn (string $path): bool => str_ends_with($path, '.pdf')));
    }
}
