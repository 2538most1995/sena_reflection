<?php

namespace Tests\Feature;

use App\Models\StudentApiConnection;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\ActiveAcademicTermService;
use App\Services\StudentApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActiveAcademicTermTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 27));
        Cache::flush();
        Http::preventStrayRequests();
        Tambon::create(['id' => 'own', 'name' => 'เสนา']);
        Tambon::create(['id' => 'other', 'name' => 'ตำบลอื่น']);
        $this->teacher = User::create(['id' => 'term-teacher', 'name' => 'ครูเสนา', 'username' => 'teacher', 'password' => 'fixture-only', 'role' => 'tambon', 'tambon' => 'own']);
    }

    private function connection(string $term = '2/2569', string $token = 'fixture-term-token'): void
    {
        StudentApiConnection::create(['id' => 1, 'token' => Crypt::encryptString($token), 'latest_term' => $term, 'verified_at' => now()]);
    }

    private function record(string $id, string $semester, string $tambon = 'own'): TeachingRecord
    {
        return TeachingRecord::create(['id' => $id, 'tambon' => $tambon, 'year' => '2569', 'semester' => $semester, 'week' => 1,
            'date' => '2026-10-27', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'subject_code' => 'TH01', 'total' => 1, 'present' => 1, 'created_by' => $this->teacher->id]);
    }

    private function draft(string $semester, string $secret, string $owner = 'term-teacher'): TeachingDraft
    {
        return TeachingDraft::create(['user_id' => $owner, 'tambon' => 'own', 'year' => '2569', 'semester' => $semester, 'week' => 1, 'status' => 'active', 'lock_version' => 1,
            'payload' => ['scope' => ['tambon' => 'own', 'year' => '2569', 'semester' => $semester, 'week' => 1, 'date' => '2026-10-27'],
                'enabled_level_codes' => ['1'], 'levels' => ['1' => ['subject' => $secret, 'attendance' => [['student_id' => 'S1', 'present' => false]], 'attendance_term' => $semester.'/2569']]]]);
    }

    public function test_dashboard_defaults_to_api_term_and_limits_records_counts_and_private_drafts_to_that_term(): void
    {
        $this->connection();
        $old = $this->record('old-record', '1');
        $current = $this->record('current-record', '2');
        $this->record('hidden-record', '2', 'other');
        $old->attendance()->create(['student_id' => 'S-OLD', 'full_name' => 'Old', 'present' => true]);
        $current->attendance()->create(['student_id' => 'S-NEW', 'full_name' => 'New', 'present' => true]);
        $this->draft('1', 'old-private-draft');
        $this->draft('2', 'current-private-draft');
        $this->draft('2', 'foreign-private-draft', 'another-owner');

        $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()->assertDontSee('old-private-draft')->assertDontSee('foreign-private-draft')
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('filters.year', '2569')->where('filters.semester', '2')
                ->where('activeTerm.term', '2/2569')->where('activeTerm.source', 'sdl')->where('summary.record_count', 1)
                ->where('summary.unique_present_students', 1)->where('olderRecordCount', 1)->where('weeklyDefaults.semester', '2')
                ->has('drafts', 1)->where('drafts.0.payload.levels.1.subject', 'current-private-draft'));
        $this->get(route('dashboard', ['year' => '2569', 'semester' => '1']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.semester', '1')->where('summary.record_count', 1)->where('records.data.0.id', 'old-record')->has('drafts', 1));
        $this->get(route('dashboard', ['scope' => 'all']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'all')->where('summary.record_count', 2)->where('summary.unique_present_students', 2)->has('drafts', 2));
        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseHas('teaching_drafts', ['status' => 'active', 'semester' => '1']);
        Http::assertNothingSent();
    }

    public function test_successful_api_fetch_advances_defaults_and_persists_the_latest_term_without_historical_downgrades(): void
    {
        $this->connection('1/2569');
        Http::fake(fn (Request $request) => str_contains($request->url(), '/teachers')
            ? Http::response(['data' => []])
            : Http::response(['data' => [], 'meta' => ['current_term' => ($request['term'] ?? '') === '1/2569' ? '1/2569' : '2/2569']]));
        app(StudentApiService::class)->fetch([], false);
        $this->assertSame('2/2569', app(ActiveAcademicTermService::class)->resolve()['term']);
        $this->assertDatabaseHas('student_api_connections', ['id' => 1, 'latest_term' => '2/2569']);
        app(StudentApiService::class)->fetch(['term' => '1/2569'], false);
        Cache::flush();
        $this->assertSame('2/2569', app(ActiveAcademicTermService::class)->resolve()['term']);
        $this->assertDatabaseHas('student_api_connections', ['id' => 1, 'latest_term' => '2/2569']);
        StudentApiConnection::whereKey(1)->update(['token' => Crypt::encryptString('rotated-fixture-token'), 'latest_term' => '1/2569']);
        $this->assertSame('1/2569', app(ActiveAcademicTermService::class)->resolve()['term']);
    }

    public function test_api_outage_keeps_known_new_term_in_new_record_and_never_falls_back_to_old_student_roster(): void
    {
        $this->connection();
        $old = $this->record('old-fallback', '1');
        $old->attendance()->create(['student_id' => 'OLD', 'full_name' => 'old-roster-must-not-appear', 'present' => true]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($this->teacher)->get(route('records.create'))->assertOk()->assertDontSee('old-roster-must-not-appear')
            ->assertInertia(fn (Assert $page) => $page->component('records/create')->where('defaults.year', '2569')->where('defaults.semester', '2')->has('groups', 0));
        $this->get(route('students'))->assertOk()->assertDontSee('old-roster-must-not-appear')
            ->assertInertia(fn (Assert $page) => $page->where('filters.term', '2/2569')->where('items.total', 0));
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseHas('record_attendance', ['record_id' => 'old-fallback', 'present' => 1]);
    }

    public function test_existing_draft_keeps_its_selected_term_and_attendance_when_source_latest_is_newer(): void
    {
        $this->connection();
        $draft = $this->draft('1', 'draft-keeps-user-text');
        $before = $draft->payload;
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/teachers')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), '/subjects')) {
                return Http::response(['data' => [], 'meta' => ['term' => $request['term']]]);
            }

            return Http::response(['data' => [], 'meta' => ['current_term' => '2/2569']]);
        });
        $this->actingAs($this->teacher)->get(route('records.create', ['draft' => $draft->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('defaults.semester', '1')->where('workspace.draft.payload.scope.semester', '1')
                ->where('workspace.draft.payload.levels.1.attendance.0.present', false)->where('workspace.draft.payload.levels.1.subject', 'draft-keeps-user-text'));
        $this->assertSame($before, $draft->fresh()->payload);
        $this->assertSame(1, $draft->fresh()->lock_version);
    }

    public function test_attendance_view_defaults_to_current_api_term_and_all_terms_require_an_explicit_filter(): void
    {
        $this->connection();
        foreach (['1', '2'] as $semester) {
            $this->record('attendance-'.$semester, $semester)->attendance()->create(['student_id' => 'S'.$semester, 'full_name' => 'Student', 'present' => true]);
        }
        $this->actingAs($this->teacher)->get(route('attendance.analysis'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.semester', '2')->where('report.summary.sessions', 1)->where('report.summary.students', 1));
        $this->get(route('attendance.analysis', ['scope' => 'all']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.sessions', 2)->where('report.summary.students', 2));
        $this->get(route('attendance.analysis', ['year' => '2569', 'semester' => '1']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.sessions', 1)->where('report.students.0.student_id', 'S1'));
        Http::assertNothingSent();
    }

    public function test_temporarily_older_api_response_cannot_move_new_forms_or_rosters_back_to_a_previous_term(): void
    {
        $this->connection();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/teachers')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), '/subjects')) {
                return Http::response(['data' => [], 'meta' => ['term' => $request['term']]]);
            }

            return Http::response(['data' => [['code' => 'OLD-STUDENT', 'name' => ['full_name' => 'old-response-student'], 'current_term' => '1/2569', 'level' => ['id' => 1]]], 'meta' => ['current_term' => '1/2569']]);
        });
        $this->actingAs($this->teacher)->get(route('records.create'))->assertOk()->assertDontSee('old-response-student')
            ->assertInertia(fn (Assert $page) => $page->where('defaults.semester', '2')->where('subjectTerm', '2/2569')->has('groups', 0));
        $this->get(route('students'))->assertOk()->assertDontSee('old-response-student')
            ->assertInertia(fn (Assert $page) => $page->where('filters.term', '2/2569')->where('items.total', 0));
        $this->assertDatabaseHas('student_api_connections', ['latest_term' => '2/2569']);
    }

    public function test_response_from_a_rotated_credential_cannot_update_the_replacement_source_term(): void
    {
        $this->connection('1/2569');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/teachers')) {
                return Http::response(['data' => []]);
            }
            if ($request->hasHeader('Authorization', 'Bearer fixture-term-token')) {
                StudentApiConnection::whereKey(1)->update(['token' => Crypt::encryptString('replacement-fixture-token'), 'latest_term' => '1/2568']);

                return Http::response(['data' => [], 'meta' => ['current_term' => '2/2569']]);
            }

            return Http::response(['data' => [], 'meta' => ['current_term' => '1/2568']]);
        });
        app(StudentApiService::class)->fetch([], false);
        $this->assertSame('1/2568', app(ActiveAcademicTermService::class)->resolve()['term']);
        $this->assertDatabaseHas('student_api_connections', ['id' => 1, 'latest_term' => '1/2568']);
        app(StudentApiService::class)->fetch([], false);
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), '/teachers')));
        $this->assertSame('1/2568', app(ActiveAcademicTermService::class)->resolve()['term']);
    }

    public function test_calendar_fallback_is_honest_and_never_uses_an_old_record_to_guess_the_active_term(): void
    {
        config(['student_api.driver' => 'legacy']);
        $this->record('legacy-future', '3');
        $resolved = app(ActiveAcademicTermService::class)->resolve();
        $this->assertSame('1/2569', $resolved['term']);
        $this->assertSame('calendar', $resolved['source']);
        $this->assertNotNull($resolved['notice']);
        Http::assertNothingSent();
    }
}
