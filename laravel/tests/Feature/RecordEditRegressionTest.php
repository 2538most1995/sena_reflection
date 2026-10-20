<?php

namespace Tests\Feature;

use App\Models\RecordDocumentLayout;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecordEditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecord(): array
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูทดสอบ', 'username' => 'teacher', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        $parent = WeeklySubmission::create(['tambon' => 't1', 'year' => '2569', 'semester' => '1', 'week' => 1, 'status' => 'complete', 'submitted_by' => 'u1']);
        $record = TeachingRecord::create([
            'id' => 'sync-record', 'weekly_submission_id' => $parent->id, 'tambon' => 't1', 'created_by' => 'u1',
            'year' => '2569', 'semester' => '1', 'week' => 1, 'date' => '2026-10-16', 'level' => 'ประถมศึกษา',
            'subject' => 'ภาษาไทย', 'result' => 'ผลเดิม', 'problem' => '', 'suggestion' => '', 'total' => 0, 'present' => 0,
        ]);

        return [$user, $record, $parent];
    }

    private function payload(TeachingRecord $record): array
    {
        return [...$record->only(['year', 'semester', 'week', 'level', 'subject', 'result', 'problem', 'suggestion', 'total', 'present']), 'date' => '2026-10-16', 'attendance' => [], 'keep_images' => []];
    }

    public function test_changing_record_text_updates_pdf_override_and_retains_previous_revision(): void
    {
        [$user, $record] = $this->setupRecord();
        RecordDocumentLayout::create(['record_id' => $record->id, 'settings' => [], 'content' => ['record_result' => 'ผลเฉพาะเอกสารเดิม'], 'updated_by' => $user->id]);

        $this->actingAs($user)->put(route('records.update', $record), [...$this->payload($record), 'result' => 'ผลใหม่ที่แก้ไขแล้ว'])->assertSessionHasNoErrors();

        $this->assertSame('ผลใหม่ที่แก้ไขแล้ว', $record->fresh()->result);
        $this->assertSame('ผลใหม่ที่แก้ไขแล้ว', $record->documentLayout()->first()->content['record_result']);
        $history = RecordRevision::where('record_id', $record->id)->orderBy('revision')->get();
        $this->assertCount(2, $history);
        $this->assertSame('ผลเฉพาะเอกสารเดิม', $history[0]->snapshot['legacy_document_content']['record_result']);
        $this->assertSame('ผลเดิม', $history[0]->snapshot['content']['record_result']);
        $this->assertSame('ผลใหม่ที่แก้ไขแล้ว', $history[1]->snapshot['content']['record_result']);
    }

    public function test_moving_a_record_updates_the_weekly_parent_and_rejects_duplicate_level(): void
    {
        [$user, $record, $oldParent] = $this->setupRecord();

        $this->actingAs($user)->put(route('records.update', $record), [...$this->payload($record), 'week' => 2])->assertSessionHasNoErrors();

        $newParent = WeeklySubmission::findOrFail($record->fresh()->weekly_submission_id);
        $this->assertSame(2, $newParent->week);
        $this->assertSame('incomplete', $newParent->status);
        $this->assertSame('incomplete', $oldParent->fresh()->status);
        TeachingRecord::create([...$record->fresh()->getAttributes(), 'id' => 'duplicate-record', 'week' => 3, 'weekly_submission_id' => null]);
        $this->put(route('records.update', $record), [...$this->payload($record), 'week' => 3])->assertSessionHasErrors('week');
        $this->assertSame(2, $record->fresh()->week);
    }
}
