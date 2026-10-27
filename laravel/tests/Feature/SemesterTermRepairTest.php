<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use App\Services\BoundDocumentService;
use App\Services\DocumentService;
use App\Services\SemesterTermRepairService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SemesterTermRepairTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $district;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->district = User::query()->create(['id' => 'repair-admin', 'name' => 'ผู้ดูแล', 'username' => 'repair-admin', 'password' => 'password', 'role' => 'district']);
        Tambon::query()->create(['id' => 't1', 'name' => 'เสนา']);
    }

    public function test_both_legacy_year_spellings_are_moved_only_after_backup_without_changing_text_images_or_attendance(): void
    {
        $service = app(SemesterTermRepairService::class);
        foreach (['256', '1/2569'] as $index => $year) {
            $record = $this->record('legacy-'.$index, $year, $index + 1);
            RecordAttendance::query()->create(['record_id' => $record->id, 'student_id' => 'student-1', 'full_name' => 'ผู้เรียนหนึ่ง', 'present' => true, 'learning_method' => 'พบกลุ่ม', 'created_at' => now()]);
            RecordImage::query()->create(['record_id' => $record->id, 'file_path' => 'uploads/original-photo.png', 'created_at' => now()]);
            $attendance = $record->attendance()->get()->toArray();
            $images = $record->images()->get()->toArray();
            $before = array_intersect_key($record->fresh()->attributesToArray(), array_flip(['result', 'problem', 'suggestion', 'total', 'present', 'date', 'subject', 'subject_code', 'level']));
            $oldParent = $record->weekly_submission_id;
            $review = $service->preview($this->district, $year, '1', '2569', '1');
            $this->assertTrue($review['can_repair']);
            $this->assertSame(1, $review['records']);
            $called = false;
            $result = $service->repair($this->district, $year, '1', '2569', '1', $review['fingerprint'], function () use ($record, $year, &$called): array {
                $this->assertSame($year, $record->fresh()->year, 'The original graph must be backed up before any row changes');
                $called = true;

                return ['id' => (string) Str::uuid(), 'year' => $year, 'semester' => '1', 'status' => 'ready'];
            });
            $this->assertTrue($called);
            $this->assertSame(1, $result['repaired']);
            $this->assertSame('2569', $record->fresh()->year);
            $this->assertSame($before, array_intersect_key($record->fresh()->attributesToArray(), $before));
            $this->assertSame($attendance, $record->attendance()->get()->toArray());
            $this->assertSame($images, $record->images()->get()->toArray());
            $this->assertDatabaseMissing('weekly_submissions', ['id' => $oldParent]);
            $parent = WeeklySubmission::query()->findOrFail($record->fresh()->weekly_submission_id);
            $this->assertSame('2569', $parent->year);
            $history = RecordRevision::query()->where('record_id', $record->id)->orderBy('revision')->get();
            $this->assertSame($year, $history->first()->snapshot['record']['year']);
            $this->assertSame('2569', $history->last()->snapshot['record']['year']);
        }
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_existing_target_parent_is_reused_and_a_different_level_is_preserved(): void
    {
        $record = $this->record('legacy', '256', 1);
        $target = $this->record('target', '2569', 1, 'มัธยมศึกษาตอนต้น');
        $targetBefore = $target->fresh()->getAttributes();
        $result = $this->repair('256');
        $this->assertSame(1, $result['repaired']);
        $this->assertSame($target->weekly_submission_id, $record->fresh()->weekly_submission_id);
        $this->assertSame($targetBefore, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('records', 2);
    }

    public function test_a_duplicate_target_level_including_a_legacy_alias_blocks_repair_before_backup(): void
    {
        $this->record('legacy', '256', 1);
        $this->record('target', '2569', 1, '1');
        $review = app(SemesterTermRepairService::class)->preview($this->district, '256', '1', '2569', '1');
        $this->assertFalse($review['can_repair']);
        $called = false;
        try {
            app(SemesterTermRepairService::class)->repair($this->district, '256', '1', '2569', '1', $review['fingerprint'], function () use (&$called): array {
                $called = true;

                return [];
            });
            $this->fail('Duplicate target was accepted');
        } catch (ValidationException) {
            $this->assertFalse($called);
            $this->assertDatabaseHas('records', ['id' => 'legacy', 'year' => '256']);
        }
    }

    public function test_active_private_drafts_and_active_exports_block_repair_without_being_modified(): void
    {
        $record = $this->record('legacy', '256', 1);
        $draft = TeachingDraft::query()->create(['user_id' => $this->district->id, 'source_record_id' => $record->id, 'year' => '2569', 'semester' => '2', 'status' => 'active', 'payload' => ['private' => 'keep unchanged']]);
        $before = $draft->fresh()->getAttributes();
        $review = app(SemesterTermRepairService::class)->preview($this->district, '256', '1', '2569', '1');
        $this->assertFalse($review['can_repair']);
        $this->assertStringContainsString('ฉบับร่าง', implode(' ', $review['blockers']));
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $draft->update(['status' => 'discarded']);
        DB::table('export_jobs')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->district->id, 'status' => 'processing', 'filters' => '{}', 'manifest' => json_encode([['record_id' => $record->id]]), 'total' => 1, 'completed' => 0, 'expires_at' => now()->addHour()]);
        $review = app(SemesterTermRepairService::class)->preview($this->district, '256', '1', '2569', '1');
        $this->assertFalse($review['can_repair']);
        $this->assertStringContainsString('งานส่งออก', implode(' ', $review['blockers']));
    }

    public function test_stale_source_or_target_changes_are_rejected_and_backup_creation_failure_never_changes_rows(): void
    {
        $record = $this->record('legacy', '256', 1);
        $service = app(SemesterTermRepairService::class);
        $review = $service->preview($this->district, '256', '1', '2569', '1');
        $record->update(['result' => 'changed while review was open']);
        try {
            $service->repair($this->district, '256', '1', '2569', '1', $review['fingerprint'], fn (): array => throw new \RuntimeException('Backup should not start'));
            $this->fail('Stale fingerprint was accepted');
        } catch (ValidationException) {
            $this->assertDatabaseHas('records', ['id' => $record->id, 'year' => '256']);
        }
        $review = $service->preview($this->district, '256', '1', '2569', '1');
        try {
            $service->repair($this->district, '256', '1', '2569', '1', $review['fingerprint'], fn (): array => throw new \RuntimeException('Disk full'));
            $this->fail('Backup exception was ignored');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Disk full', $exception->getMessage());
            $this->assertDatabaseHas('records', ['id' => $record->id, 'year' => '256']);
        }
        $target = $this->record('other-target', '2569', 1, 'มัธยมศึกษาตอนต้น');
        $review = $service->preview($this->district, '256', '1', '2569', '1');
        try {
            $service->repair($this->district, '256', '1', '2569', '1', $review['fingerprint'], function () use ($target): array {
                $target->update(['problem' => 'changed during backup']);

                return ['id' => 'retained-backup', 'year' => '256', 'semester' => '1'];
            });
            $this->fail('Concurrent change during backup was accepted');
        } catch (ValidationException) {
            $this->assertDatabaseHas('records', ['id' => $record->id, 'year' => '256']);
        }
    }

    public function test_bound_editor_anchors_custom_formatting_and_history_are_preserved(): void
    {
        $record = $this->record('bound-legacy', '1/2569', 3);
        $bound = app(BoundDocumentService::class)->forRecord($this->district, $record);
        $content = $bound->content_json;
        $content['content'][] = ['type' => 'paragraph', 'attrs' => ['id' => 'custom-anchor'], 'content' => [['type' => 'text', 'text' => 'ข้อความผู้ใช้คงเดิม', 'marks' => [['type' => 'bold']]]]];
        // An existing customized editor, with a version already stored, is rebased rather than replaced.
        app(DocumentService::class)->update($bound, $this->district, $bound->revision, ['content' => $content]);
        $beforeVersions = $bound->versions()->count();
        $originalId = $bound->id;
        $this->repair('1/2569');
        $fresh = Document::query()->findOrFail($originalId);
        $encoded = json_encode($fresh->content_json, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('custom-anchor', $encoded);
        $this->assertStringContainsString('ข้อความผู้ใช้คงเดิม', $encoded);
        $this->assertStringContainsString('bold', $encoded);
        $this->assertGreaterThan($beforeVersions, $fresh->versions()->count());
        $this->assertDatabaseCount('documents', 1);
        $history = RecordRevision::query()->where('record_id', $record->id)->orderBy('revision')->get();
        $this->assertSame('1/2569', $history->first()->snapshot['record']['year']);
        $this->assertSame('2569', $history->last()->snapshot['record']['year']);
        $this->assertSame($originalId, $history->last()->snapshot['advanced_document']['id']);
        $this->assertSame('2569', $fresh->binding_snapshot['source_snapshot']['record']['year']);
    }

    public function test_unrelated_destination_weeks_are_excluded_from_the_repair_fingerprint(): void
    {
        $this->record('legacy', '256', 1);
        $unrelated = $this->record('unrelated-target', '2569', 20);
        $service = app(SemesterTermRepairService::class);
        $before = $service->preview($this->district, '256', '1', '2569', '1');
        $unrelated->update(['result' => 'ผู้ใช้อื่นแก้คนละสัปดาห์']);
        $after = $service->preview($this->district, '256', '1', '2569', '1');
        $this->assertSame($before['fingerprint'], $after['fingerprint']);
        $this->assertTrue($after['can_repair']);
    }

    public function test_only_district_can_preview_or_repair_and_target_period_must_be_canonical(): void
    {
        $this->record('legacy', '256', 1);
        $teacher = User::query()->create(['id' => 'repair-teacher', 'name' => 'ครู', 'username' => 'repair-teacher', 'password' => 'password', 'role' => 'tambon', 'tambon' => 't1']);
        try {
            app(SemesterTermRepairService::class)->preview($teacher, '256', '1', '2569', '1');
            $this->fail('Teacher was allowed to repair a district period');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        foreach ([['256', '1'], ['1/2569', '1'], ['2569', '3']] as [$year, $semester]) {
            try {
                app(SemesterTermRepairService::class)->preview($this->district, '256', '1', $year, $semester);
                $this->fail('Malformed target period was accepted');
            } catch (ValidationException) {
                $this->assertDatabaseHas('records', ['id' => 'legacy', 'year' => '256']);
            }
        }
    }

    private function record(string $id, string $year, int $week, string $level = 'ประถมศึกษา'): TeachingRecord
    {
        $parent = WeeklySubmission::query()->firstOrCreate(['tambon' => 't1', 'year' => $year, 'semester' => '1', 'week' => $week], ['submitted_by' => $this->district->id, 'status' => 'incomplete']);

        return TeachingRecord::query()->create(['id' => $id, 'weekly_submission_id' => $parent->id, 'tambon' => 't1', 'created_by' => $this->district->id,
            'year' => $year, 'semester' => '1', 'week' => $week, 'date' => '2026-05-17', 'level' => $level,
            'subject' => 'ภาษาไทย', 'subject_code' => 'พท11001', 'result' => 'ข้อความต้นฉบับ', 'problem' => 'ปัญหาเดิม', 'suggestion' => 'แนวทางเดิม', 'total' => 8, 'present' => 3]);
    }

    private function repair(string $sourceYear): array
    {
        $service = app(SemesterTermRepairService::class);
        $review = $service->preview($this->district, $sourceYear, '1', '2569', '1');

        return $service->repair($this->district, $sourceYear, '1', '2569', '1', $review['fingerprint'], fn (): array => ['id' => (string) Str::uuid(), 'year' => $sourceYear, 'semester' => '1', 'status' => 'ready']);
    }
}
