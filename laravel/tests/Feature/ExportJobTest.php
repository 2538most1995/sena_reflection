<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordExport;
use App\Models\ExportJob;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use App\Services\RecordExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportJobTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(string $id = 'exportteacher', string $tambon = 't1'): User
    {
        Tambon::firstOrCreate(['id' => $tambon], ['name' => 'ตำบล '.$tambon]);

        return User::create(['id' => $id, 'username' => $id, 'name' => 'ครูทดสอบเอกสาร', 'role' => 'tambon', 'tambon' => $tambon, 'password' => Hash::make('test-password')]);
    }

    private function record(User $user, string $id = 'exportrecord'): TeachingRecord
    {
        return TeachingRecord::create([
            'id' => $id, 'tambon' => $user->tambon, 'created_by' => $user->id,
            'year' => '2569', 'semester' => '1', 'week' => 8, 'date' => '2026-10-16',
            'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'result' => 'ข้อความฉบับที่ตรวจแล้ว', 'total' => 3, 'present' => 2,
        ]);
    }

    public function test_export_captures_an_immutable_revision_and_runs_in_the_background(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        Storage::fake('local');
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $this->record($this->teacher('otherteacher', 't2'), 'otherrecord');
        $response = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all', 'year' => '2569']);
        $response->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('total', 1);
        $export = ExportJob::findOrFail($response->json('id'));
        $this->assertSame($record->id, $export->manifest[0]['record_id']);
        Queue::assertPushed(GenerateRecordExport::class, fn ($job) => $job->exportId === $export->id && $job->connection === 'exports');
        $record->update(['result' => 'ข้อความที่แก้ภายหลัง']);

        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->withArgs(fn ($snapshot, $user) => $snapshot['record']['result'] === 'ข้อความฉบับที่ตรวจแล้ว' && $user->id === $teacher->id)->andReturn('%PDF-1.4 test snapshot');
        (new GenerateRecordExport($export->id))->handle(app(RecordExportService::class), $print);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->completed);
        $this->get('/exports/'.$export->id.'/download')->assertOk()->assertHeader('content-type', 'application/zip');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->path)));
        $this->assertNotFalse($zip->getFromName($record->id.'.pdf'));
        $zip->close();
    }

    public function test_export_access_is_private_and_expiry_is_enforced(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        $teacher = $this->teacher();
        $this->record($teacher);
        $response = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all']);
        $id = $response->json('id');
        $other = $this->teacher('otherteacher', 't2');
        $this->actingAs($other)->getJson('/exports/'.$id)->assertNotFound();
        $this->postJson('/exports/'.$id.'/retry')->assertNotFound();
        $this->get('/exports/'.$id.'/download')->assertNotFound();
        $this->actingAs($teacher)->getJson('/exports/'.$id)->assertOk();
        ExportJob::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/exports/'.$id)->assertGone();
        Queue::assertPushed(GenerateRecordExport::class, 1);
    }

    public function test_failed_export_can_be_retried_without_losing_the_revision(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        $teacher = $this->teacher();
        $this->record($teacher);
        $id = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->json('id');
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->andThrow(new \RuntimeException('renderer unavailable'));
        (new GenerateRecordExport($id))->handle(app(RecordExportService::class), $print);
        $this->assertSame('failed', ExportJob::find($id)->status);
        $this->assertSame(1, RecordRevision::count());
        $this->postJson('/exports/'.$id.'/retry')->assertStatus(202)->assertJsonPath('status', 'queued');
        Queue::assertPushed(GenerateRecordExport::class, 2);
    }

    public function test_export_requires_authentication_and_valid_period(): void
    {
        $this->postJson('/exports', ['scope' => 'all'])->assertUnauthorized();
        $teacher = $this->teacher();
        $this->actingAs($teacher)->postJson('/exports', ['scope' => 'week'])->assertUnprocessable();
        $this->postJson('/exports', ['scope' => 'all'])->assertUnprocessable()->assertJsonPath('message', 'ไม่พบบันทึกตามตัวกรองที่เลือก');
    }

    public function test_identical_requests_reuse_a_queued_job_even_when_active_limit_is_full(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        $teacher = $this->teacher();
        $this->record($teacher);
        $filters = ['scope' => 'week', 'week' => 8, 'year' => '2569', 'semester' => '1'];
        $first = $this->actingAs($teacher)->postJson('/exports', $filters)->assertAccepted();
        foreach ([1, 2] as $index) {
            ExportJob::create(['id' => (string) Str::uuid(), 'user_id' => $teacher->id, 'status' => 'queued', 'filters' => ['scope' => 'all'], 'manifest' => [], 'total' => 1, 'expires_at' => now()->addDay()]);
        }
        $this->postJson('/exports', [...$filters, 'week' => '8', 'tambon' => 'foreign-scope'])
            ->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/exports', ['scope' => 'all'])->assertTooManyRequests();
        $this->assertDatabaseCount('export_jobs', 3);
        Queue::assertPushed(GenerateRecordExport::class, 1);
    }

    public function test_ready_file_is_reused_but_changed_record_requires_a_new_frozen_revision(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        Storage::fake('local');
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $first = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-frozen');
        (new GenerateRecordExport($first->json('id')))->handle(app(RecordExportService::class), $print);
        $this->postJson('/exports', ['scope' => 'all'])->assertOk()
            ->assertJsonPath('id', $first->json('id'))->assertJsonPath('status', 'ready');
        $record->update(['result' => 'changed after ready']);
        $changed = $this->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        $this->assertNotSame($first->json('id'), $changed->json('id'));
        $this->assertDatabaseCount('record_revisions', 2);
        Queue::assertPushed(GenerateRecordExport::class, 2);
    }

    public function test_legacy_pending_job_can_be_reused_and_missing_ready_file_is_rebuilt(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->record($teacher);
        $first = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        ExportJob::whereKey($first->json('id'))->update(['fingerprint' => null]);
        $this->postJson('/exports', ['scope' => 'all'])->assertOk()->assertJsonPath('id', $first->json('id'));
        ExportJob::whereKey($first->json('id'))->update(['status' => 'ready', 'path' => 'missing.pdf']);
        $this->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        Queue::assertPushed(GenerateRecordExport::class, 2);
    }

    public function test_new_renderer_does_not_reuse_a_ready_export_from_an_old_build(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->record($teacher);
        $service = $this->mock(RecordExportService::class)->makePartial();
        $service->shouldReceive('rendererFingerprint')->once()->andReturn('old-renderer');
        $service->shouldReceive('rendererFingerprint')->once()->andReturn('new-renderer');
        $first = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        Storage::disk('local')->put('old.pdf', '%PDF-old');
        ExportJob::whereKey($first->json('id'))->update(['status' => 'ready', 'path' => 'old.pdf']);
        $next = $this->postJson('/exports', ['scope' => 'all'])->assertAccepted();
        $this->assertNotSame($first->json('id'), $next->json('id'));
    }

    public function test_reused_ready_job_remains_visible_after_status_refresh(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->record($teacher);
        $id = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted()->json('id');
        Storage::disk('local')->put('ready.pdf', '%PDF-ready');
        ExportJob::whereKey($id)->update(['status' => 'ready', 'path' => 'ready.pdf']);
        $this->travel(1)->seconds();
        foreach (range(1, 25) as $index) {
            ExportJob::create(['id' => (string) Str::uuid(), 'user_id' => $teacher->id, 'status' => 'ready', 'filters' => ['scope' => 'all'], 'manifest' => [], 'total' => 1, 'expires_at' => now()->addDay()]);
        }
        $this->travel(1)->seconds();
        $this->postJson('/exports', ['scope' => 'all'])->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/exports')->assertOk()->assertJsonPath('items.0.id', $id);
    }

    public function test_retry_respects_active_limit_and_reuses_an_already_queued_copy(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        $teacher = $this->teacher();
        $this->record($teacher);
        $id = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted()->json('id');
        ExportJob::whereKey($id)->update(['status' => 'failed']);
        $active = [];
        foreach (range(1, 3) as $index) {
            $active[] = ExportJob::create(['id' => (string) Str::uuid(), 'user_id' => $teacher->id, 'status' => 'queued', 'filters' => ['scope' => 'all'], 'manifest' => [], 'total' => 1, 'expires_at' => now()->addDay()]);
        }
        $this->postJson('/exports/'.$id.'/retry')->assertTooManyRequests();
        $active[0]->update(['status' => 'ready']);
        $this->postJson('/exports/'.$id.'/retry')->assertAccepted();
        $copy = ExportJob::findOrFail($id)->replicate();
        $copy->id = (string) Str::uuid();
        $copy->status = 'failed';
        $copy->save();
        $this->postJson('/exports/'.$copy->id.'/retry')->assertOk()->assertJsonPath('id', $id);
        Queue::assertPushed(GenerateRecordExport::class, 2);
    }

    public function test_hosting_worker_drains_an_actual_database_export_job(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->record($teacher);
        $id = $this->actingAs($teacher)->postJson('/exports', ['scope' => 'all'])->assertAccepted()->json('id');
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-background-worker');
        $this->artisan('sena:work-exports')->assertSuccessful();
        $this->assertSame('ready', ExportJob::findOrFail($id)->status);
        $this->assertSame(1, ExportJob::findOrFail($id)->completed);
        $this->assertDatabaseCount('jobs', 0);
        $this->get('/exports/'.$id.'/download')->assertOk();
    }

    public function test_cache_pruning_preserves_an_in_use_pdf_and_its_stable_lock(): void
    {
        Storage::fake('local');
        $file = 'export-cache/user/old.pdf';
        Storage::disk('local')->put($file, '%PDF-old');
        $path = Storage::disk('local')->path($file);
        touch($path, now()->subDays(8)->timestamp);
        $lock = fopen($path.'.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        touch($path.'.lock', now()->subDays(8)->timestamp);
        try {
            $this->artisan('sena:prune-exports')->assertSuccessful();
            $this->assertFileExists($path);
            $this->assertFileExists($path.'.lock');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->artisan('sena:prune-exports')->assertSuccessful();
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($path.'.lock');
    }

    public function test_reviewing_three_pdf_previews_does_not_exhaust_export_limit(): void
    {
        Queue::fake([GenerateRecordExport::class]);
        $teacher = $this->teacher();
        $this->record($teacher);
        $draft = $this->actingAs($teacher)->postJson('/teaching-drafts', ['payload' => [
            'scope' => ['tambon' => 't1', 'week' => 9, 'year' => '2569', 'semester' => '1', 'date' => '2026-10-17'],
            'levels' => ['1' => ['subject' => 'ภาษาไทย', 'result' => 'ผลการสอน']],
        ]])->assertCreated()->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldReceive('snapshotPdf')->times(3)->andReturn('%PDF-review');
        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/teaching-drafts/'.$draft.'/preview', ['lock_version' => 1, 'level_code' => '1'])->assertOk();
        }

        $this->postJson('/exports', ['scope' => 'week', 'week' => 8, 'year' => '2569', 'semester' => '1'])
            ->assertAccepted()->assertJsonPath('status', 'queued');
        Queue::assertPushed(GenerateRecordExport::class, 1);
    }
}
