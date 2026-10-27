<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcademicPeriodValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private TeachingRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Tambon::create(['id' => 'period-t1', 'name' => 'ตำบลทดสอบ']);
        $this->teacher = User::create(['id' => 'period-u1', 'name' => 'ครูทดสอบ', 'username' => 'period-teacher', 'password' => 'secret', 'role' => 'tambon', 'tambon' => 'period-t1']);
        $this->record = TeachingRecord::create([
            'id' => 'period-record', 'tambon' => 'period-t1', 'created_by' => $this->teacher->id,
            'year' => '2569', 'semester' => '1', 'week' => 1, 'date' => '2026-10-26',
            'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'result' => 'เรียนตามแผน', 'total' => 0, 'present' => 0,
        ]);
        $this->actingAs($this->teacher);
    }

    public static function invalidPeriods(): array
    {
        return [
            'short year' => ['year', '256'],
            'combined semester and year' => ['year', '1/2569'],
            'extra year digit must not be truncated' => ['year', '25699'],
            'trailing text must not be truncated' => ['year', '2569 ภาคเรียน1'],
            'inconsistent out of century year' => ['year', '2669'],
            'combined semester' => ['semester', '1/1/2569'],
            'unsupported semester' => ['semester', '0'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_all_record_writers_reject_bad_periods_before_changing_data(string $field, string $value): void
    {
        $record = [...$this->record->only(['year', 'semester', 'week', 'level', 'subject', 'result', 'total', 'present']), 'date' => '2026-10-26', 'attendance' => [], 'keep_images' => []];
        $weekly = [
            'year' => '2569', 'semester' => '1', 'week' => 2, 'date' => '2026-10-26',
            'records' => array_map(static fn (string $level): array => ['level' => $level, 'subject' => 'วิชาทดสอบ', 'total' => 0, 'present' => 0], ['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย']),
        ];
        $this->postJson(route('weekly-submissions.store'), [...$weekly, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson(route('records.update', $this->record), [...$record, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->postJson('/api/records.php', [...$record, $field => $value])->assertJsonPath('status', 'error');
        $this->putJson('/api/records.php?id='.$this->record->id, [...$record, $field => $value])->assertJsonPath('status', 'error');

        $draft = $this->draftPayload();
        $draft['scope'][$field] = $value;
        $this->postJson(route('teaching-drafts.store'), ['payload' => $draft])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.scope.'.$field);
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('weekly_submissions', 0);
        $this->assertDatabaseCount('teaching_drafts', 0);
        $this->assertSame('2569', $this->record->fresh()->year);
        $this->assertSame('1', $this->record->fresh()->semester);
    }

    #[DataProvider('invalidPeriods')]
    public function test_autosave_and_finalization_preserve_an_existing_draft_when_the_period_is_invalid(string $field, string $value): void
    {
        $payload = $this->draftPayload();
        $id = $this->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $saved = TeachingDraft::findOrFail($id)->payload;
        $invalid = $saved;
        $invalid['scope'][$field] = $value;
        $this->patchJson(route('teaching-drafts.update', $id), ['lock_version' => 1, 'payload' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.scope.'.$field);
        $this->assertSame($saved, TeachingDraft::findOrFail($id)->payload);

        // A legacy draft may already contain malformed data from an older writer.
        TeachingDraft::findOrFail($id)->update(['payload' => $invalid]);
        $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.scope.'.$field);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $id, 'status' => 'active', 'lock_version' => 1]);
        $this->assertSame($invalid['levels'], TeachingDraft::findOrFail($id)->payload['levels']);
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('weekly_submissions', 0);
    }

    public function test_legacy_third_semester_remains_supported_without_free_form_terms(): void
    {
        $payload = $this->draftPayload();
        $payload['scope']['semester'] = '3';
        $this->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()
            ->assertJsonPath('data.draft.payload.scope.year', '2569')
            ->assertJsonPath('data.draft.payload.scope.semester', '3');
    }

    private function draftPayload(): array
    {
        return [
            'schema_version' => 1,
            'scope' => ['tambon' => 'period-t1', 'year' => '2569', 'semester' => '1', 'week' => 2, 'date' => '2026-10-26'],
            'active_level_code' => '1', 'enabled_level_codes' => ['1'],
            'levels' => ['1' => [
                'subject' => 'ภาษาไทย', 'class_group_code' => 'G1', 'class_name' => 'กลุ่มทดสอบ',
                'result' => 'ผู้เรียนฝึกอ่าน', 'total' => 1, 'present' => 1, 'attendance_mode' => 'roster',
                'attendance' => [['student_id' => 'S1', 'full_name' => 'ผู้เรียนทดสอบ', 'group_code' => 'G1', 'present' => true, 'learning_method' => 'พบกลุ่ม']],
                'image_ids' => [],
            ]],
        ];
    }
}
