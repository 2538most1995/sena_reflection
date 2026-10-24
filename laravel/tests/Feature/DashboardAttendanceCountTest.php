<?php

namespace Tests\Feature;

use App\Models\RecordAttendance;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardAttendanceCountTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Tambon::create(['id' => 'own', 'name' => 'Own']);
        Tambon::create(['id' => 'other', 'name' => 'Other']);
        $this->teacher = User::create(['id' => 'teacher', 'name' => 'Teacher', 'username' => 'teacher', 'password' => Hash::make('fixture-only'), 'role' => 'tambon', 'tambon' => 'own']);
    }

    private function record(string $id, array $attributes = []): TeachingRecord
    {
        return TeachingRecord::create([
            'id' => $id, 'tambon' => 'own', 'year' => '2569', 'semester' => '1', 'week' => 1,
            'date' => '2026-10-24', 'level' => 'secondary', 'subject' => 'Online', 'subject_code' => 'ON01',
            'total' => 26, 'present' => 4, 'created_by' => $this->teacher->id, ...$attributes,
        ]);
    }

    private function attendance(TeachingRecord $record, string $studentId, bool $present = true): void
    {
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => $studentId, 'full_name' => 'Student', 'present' => $present]);
    }

    private function assertCountFor(User $user, int $count, array $filters = []): void
    {
        $this->actingAs($user)->get(route('dashboard', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('summary.unique_present_students', $count));
    }

    public function test_only_present_students_are_counted_once_across_records_and_weeks(): void
    {
        $first = $this->record('first');
        $second = $this->record('second', ['week' => 2]);
        $this->attendance($first, '0001');
        $this->attendance($second, ' 0001 ');
        $this->attendance($second, '0001');
        $this->attendance($first, '0002', false);
        $this->attendance($second, '0002');
        $this->attendance($second, '0003', false);
        $this->attendance($second, '   ');
        $this->attendance($second, '');
        $this->attendance($this->record('hidden', ['tambon' => 'other']), 'hidden-student');
        $this->record('legacy-totals-only', ['total' => 100, 'present' => 99]);
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('summary.unique_present_students', 2)
                ->where('summary.total_students', 152)
                ->where('summary.total_present', 107));
        $this->assertCountFor($this->teacher, 1, ['week' => 1]);
        $this->assertCountFor($this->teacher, 2, ['week' => 2]);
    }

    public function test_count_respects_all_filters_and_tambon_users_cannot_expand_scope(): void
    {
        $this->attendance($this->record('match'), '0001');
        $this->attendance($this->record('year', ['year' => '2568']), '0002');
        $this->attendance($this->record('term', ['semester' => '2']), '0003');
        $this->attendance($this->record('week', ['week' => 2]), '0004');
        $this->attendance($this->record('subject', ['subject' => 'Other', 'subject_code' => 'XX']), '0005');
        $this->attendance($this->record('hidden', ['tambon' => 'other']), '0006');
        $filters = ['year' => '2569', 'semester' => '1', 'week' => 1, 'search' => 'ON01'];
        $this->assertCountFor($this->teacher, 1, [...$filters, 'tambon' => 'other']);
        $district = User::create(['id' => 'district', 'name' => 'District', 'username' => 'district', 'password' => Hash::make('fixture-only'), 'role' => 'district']);
        $this->assertCountFor($district, 2, $filters);
        $this->assertCountFor($district, 1, [...$filters, 'tambon' => 'other']);
        $this->assertCountFor($district, 0, [...$filters, 'search' => 'missing']);
    }

    public function test_count_covers_all_matching_records_instead_of_only_the_visible_page(): void
    {
        foreach (range(1, 31) as $index) {
            $this->attendance($this->record('record-'.$index), 'student-'.$index);
        }
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->has('records.data', 30)->where('summary.unique_present_students', 31));
    }

    public function test_empty_or_absent_only_attendance_returns_zero(): void
    {
        $this->assertCountFor($this->teacher, 0);
        $this->attendance($this->record('absent'), '0001', false);
        $this->record('totals-only');
        $this->assertCountFor($this->teacher, 0);
    }
}
