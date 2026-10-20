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
