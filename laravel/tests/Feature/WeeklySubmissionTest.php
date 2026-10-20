<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class WeeklySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Tambon::query()->create(['id' => '1214120001', 'name' => 'เสนา']);
        $this->user = User::query()->create([
            'id' => 'u1', 'name' => 'ครูทดสอบ', 'username' => 'teacher',
            'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => '1214120001',
        ]);
    }

    public function test_three_levels_are_saved_atomically(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 3);
        $this->assertSame(3, TeachingRecord::query()->distinct()->count('level'));
    }

    public function test_api_attendance_is_saved_and_recalculates_totals(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['total'] = 99;
        $payload['records'][0]['present'] = 99;
        $payload['records'][0]['attendance'] = [
            ['student_id' => 'S001', 'full_name' => 'ผู้เรียนหนึ่ง', 'group_code' => 'G01', 'class_name' => 'กลุ่มเสนา', 'present' => true, 'learning_method' => 'พบกลุ่ม'],
            ['student_id' => 'S002', 'full_name' => 'ผู้เรียนสอง', 'group_code' => 'G01', 'class_name' => 'กลุ่มเสนา', 'present' => false, 'learning_method' => 'พบกลุ่ม'],
        ];

        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $payload)->assertSessionHasNoErrors();

        $record = TeachingRecord::query()->where('level', 'ประถมศึกษา')->firstOrFail();
        $this->assertSame(2, $record->total);
        $this->assertSame(1, $record->present);
        $this->assertDatabaseCount('record_attendance', 2);
        $this->assertDatabaseHas('record_attendance', ['record_id' => $record->id, 'student_id' => 'S001', 'present' => true]);
    }

    public function test_missing_level_rejects_the_whole_submission(): void
    {
        $payload = $this->payload();
        array_pop($payload['records']);

        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $payload)
            ->assertSessionHasErrors('records');
        $this->assertDatabaseCount('weekly_submissions', 0);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_duplicate_week_is_rejected_without_extra_records(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload())
            ->assertSessionHasErrors('week');
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 3);
    }

    public function test_tambon_user_cannot_override_own_scope(): void
    {
        Tambon::query()->create(['id' => '1214120002', 'name' => 'สามกอ']);
        $payload = $this->payload();
        $payload['tambon'] = '1214120002';

        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('weekly_submissions', ['tambon' => '1214120001']);
        $this->assertDatabaseMissing('weekly_submissions', ['tambon' => '1214120002']);
    }

    public function test_dashboard_and_weekly_pdf_use_the_authenticated_scope(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());

        $this->get(route('dashboard', ['year' => '2569', 'semester' => '1', 'week' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->has('records.data', 3)
                ->where('records.data.2.subject', 'วิชา 1'));

        $response = $this->get(route('records.download', [
            'scope' => 'week', 'year' => '2569', 'semester' => '1', 'week' => 1,
        ]));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_district_dashboard_ignores_the_incompatible_legacy_tambon_cache(): void
    {
        Cache::put('tambons:list', ['เสนา'], 600);
        $district = User::query()->create([
            'id' => 'district-1', 'name' => 'ผู้ดูแลอำเภอ', 'username' => 'district',
            'password' => Hash::make('secret'), 'role' => 'district', 'tambon' => null,
        ]);

        $this->actingAs($district)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('tambonOptions.0.id', '1214120001')
                ->where('tambonOptions.0.name', 'เสนา'));
    }

    public function test_download_all_returns_a_zip_with_pdf_files_and_index(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());
        $response = $this->get(route('records.download', ['scope' => 'all']));

        $response->assertOk()->assertHeader('content-type', 'application/zip');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('index.csv'));
        $this->assertSame(4, $zip->numFiles);
        $zip->close();
        @unlink($path);
    }

    public function test_record_update_bulk_upserts_attendance_and_recalculates_totals(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());
        $record = TeachingRecord::query()->firstOrFail();
        $this->get(route('records.edit', $record))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('records/edit')->where('record.id', $record->id));
        $payload = $record->only(['week', 'semester', 'year', 'level', 'subject', 'subject_code', 'class_group_code', 'class_name', 'result', 'problem', 'suggestion']);
        $payload['date'] = $record->date->format('Y-m-d');
        $payload['total'] = 2;
        $payload['present'] = 1;
        $payload['attendance'] = [
            ['student_id' => 's1', 'full_name' => 'หนึ่ง', 'present' => 1, 'learning_method' => 'พบกลุ่ม'],
            ['student_id' => 's2', 'full_name' => 'สอง', 'present' => 0, 'learning_method' => 'ออนไลน์'],
        ];
        $this->put(route('records.update', $record), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('record_attendance', 2);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'total' => 2, 'present' => 1]);
    }

    public function test_record_can_be_viewed_and_printed_as_an_inline_pdf(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());
        $record = TeachingRecord::query()->firstOrFail();

        $this->get(route('records.show', $record))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('records/show')
                ->where('record.id', $record->id)
                ->where('record.subject', $record->subject));

        $printResponse = $this->get(route('records.print', $record));
        $printResponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', (string) $printResponse->headers->get('content-disposition'));
    }

    public function test_other_tambon_cannot_edit_or_delete_record(): void
    {
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload());
        Tambon::query()->create(['id' => '1214120002', 'name' => 'สามกอ']);
        $other = User::query()->create(['id' => 'u2', 'name' => 'ครูอื่น', 'username' => 'other', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => '1214120002']);
        $record = TeachingRecord::query()->firstOrFail();
        $this->actingAs($other)->get(route('records.show', $record))->assertForbidden();
        $this->actingAs($other)->get(route('records.print', $record))->assertForbidden();
        $this->actingAs($other)->get(route('records.edit', $record))->assertForbidden();
        $this->actingAs($other)->delete(route('records.destroy', $record))->assertForbidden();
    }

    public function test_incomplete_legacy_week_can_be_completed_without_duplicate_level(): void
    {
        $submission = WeeklySubmission::create(['tambon' => '1214120001', 'week' => 1, 'semester' => '1', 'year' => '2569', 'submitted_by' => 'u1', 'status' => 'incomplete']);
        TeachingRecord::create(['id' => 'rlegacy0000000000001', 'weekly_submission_id' => $submission->id, 'tambon' => '1214120001', 'week' => 1, 'date' => '2026-08-01', 'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'เก่า', 'total' => 1, 'present' => 1, 'created_by' => 'u1']);
        $this->actingAs($this->user)->post(route('weekly-submissions.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseHas('records', ['id' => 'rlegacy0000000000001', 'subject' => 'วิชา 1']);
        $this->assertDatabaseHas('weekly_submissions', ['id' => $submission->id, 'status' => 'complete']);
    }

    private function payload(): array
    {
        return [
            'week' => 1, 'date' => '2026-08-30', 'semester' => '1', 'year' => '2569',
            'records' => collect(['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'])->map(fn ($level, $index) => [
                'level' => $level, 'subject' => 'วิชา '.($index + 1), 'total' => 20, 'present' => 18,
                'result' => 'เรียนรู้ตามแผน', 'problem' => '', 'suggestion' => '',
            ])->all(),
        ];
    }
}
