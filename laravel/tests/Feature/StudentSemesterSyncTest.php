<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\StudentApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentSemesterSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['student_api' => ['driver' => 'sdl', 'url' => StudentApiService::SDL_URL, 'token' => 'test-sdl-token', 'key' => '', 'fallback_urls' => []]]);
    }

    private function teacher(): User
    {
        Tambon::create(['id' => 'own', 'name' => 'เสนา']);

        return User::create(['id' => 'teacher', 'name' => 'ครูทดสอบ', 'username' => 'teacher', 'password' => 'secret', 'role' => 'tambon', 'tambon' => 'own']);
    }

    private function student(string $id, string $term, string $group = 'G1'): array
    {
        return ['code' => $id, 'name' => ['full_name' => 'นักศึกษา '.$id], 'group' => ['code' => $group, 'name' => 'กลุ่มเสนา'], 'level' => ['id' => 1, 'label' => 'ประถมศึกษา'], 'current_term' => $term];
    }

    private function fakeSdl(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/teachers')) {
                return Http::response(['data' => [['username' => 'teacher', 'name' => 'ครูทดสอบ', 'assigned_groups' => ['G1']]], 'meta' => ['pagination' => ['last_page' => 1]]]);
            }
            $term = $request['term'] ?? '2/2569';

            return Http::response(['data' => [$this->student($term === '1/2569' ? 'OLD' : 'NEW', $term)], 'meta' => [
                'pagination' => ['last_page' => 1, 'total' => 1, 'per_page' => 100],
                'filter_options' => ['terms' => [['value' => '1/2569'], ['value' => '2/2569']]],
            ]]);
        });
    }

    public function test_new_record_defaults_to_latest_api_term_and_sends_secure_sdl_contract(): void
    {
        $this->fakeSdl();
        $this->actingAs($this->teacher())->get(route('records.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaults.semester', '2')->where('defaults.year', '2569')->where('groups.0.group_code', 'G1'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/students') && $request->hasHeader('Authorization', 'Bearer test-sdl-token') && $request->hasHeader('X-Student-Data-Token', 'test-sdl-token') && $request['per_page'] === 100 && ! isset($request['api_key']) && ! str_contains($request->url(), 'test-sdl-token'));
    }

    public function test_initial_directory_and_group_options_only_include_latest_term_when_api_returns_multiple_terms(): void
    {
        Http::fake([
            '*/teachers*' => Http::response(['data' => [['username' => 'teacher', 'assigned_groups' => ['G1', 'OLD-GROUP']]]]),
            '*/students*' => Http::response(['data' => [
                $this->student('OLD', '1/2569', 'OLD-GROUP'),
                $this->student('NEW-1', '2/2569'),
                $this->student('NEW-2', '2/2569'),
            ], 'meta' => ['filter_options' => ['terms' => [['value' => '1/2569'], ['value' => '2/2569']]]]]),
        ]);
        $this->actingAs($this->teacher())->get(route('students'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.term', '2/2569')->has('items.data', 2)
            ->where('items.data.0.student_id', 'NEW-1')->where('items.data.1.student_id', 'NEW-2'));
        $this->get(route('records.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaults.semester', '2')->has('groups', 1)
            ->where('groups.0.group_code', 'G1')->where('groups.0.student_count', 2));
    }

    public function test_picker_cache_is_separate_per_term_and_refresh_fetches_again(): void
    {
        $this->fakeSdl();
        $this->actingAs($this->teacher());
        $filters = ['group_code' => 'G1', 'level' => '1', 'year' => '2569', 'semester' => '1'];
        $this->getJson(route('record-options.students', $filters))->assertOk()->assertJsonPath('data.items.0.student_id', 'OLD');
        $this->getJson(route('record-options.students', [...$filters, 'semester' => '2']))->assertOk()->assertJsonPath('data.items.0.student_id', 'NEW');
        $this->getJson(route('record-options.students', $filters))->assertOk()->assertJsonPath('data.items.0.student_id', 'OLD');
        Http::assertSentCount(3);
        $this->getJson(route('record-options.students', [...$filters, 'refresh' => 1]))->assertOk();
        Http::assertSentCount(5);
    }

    public function test_sdl_does_not_scope_by_similar_tambon_name_without_teacher_assignment(): void
    {
        Http::fake([
            '*/teachers*' => Http::response(['data' => [['username' => 'other', 'name' => 'ครูอื่น', 'assigned_groups' => ['G1']]]]),
            '*/students*' => Http::response(['data' => [$this->student('UNAUTHORIZED', '2/2569')]]),
        ]);
        $this->actingAs($this->teacher())->getJson(route('record-options.students', ['group_code' => 'G1', 'level' => '1', 'year' => '2569', 'semester' => '2']))
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_sdl_scope_requires_exact_username_even_when_teacher_display_names_match(): void
    {
        Http::fake([
            '*/teachers*' => Http::response(['data' => [['username' => 'te-acher', 'name' => 'ครูทดสอบ', 'assigned_groups' => ['G1']]]]),
            '*/students*' => Http::response(['data' => [$this->student('UNAUTHORIZED', '2/2569')]]),
        ]);
        $this->actingAs($this->teacher())->get(route('students', ['term' => '2/2569']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    public function test_sdl_fetches_all_student_and_teacher_pages_before_scoping(): void
    {
        Http::fake(function (Request $request) {
            $page = (int) $request['page'];
            $rows = str_contains($request->url(), '/teachers')
                ? [['username' => $page === 1 ? 'other' : 'teacher', 'assigned_groups' => [$page === 1 ? 'OTHER' : 'G1']]]
                : [$this->student('S'.$page, '2/2569')];

            return Http::response(['data' => $rows, 'meta' => ['pagination' => ['last_page' => 2, 'current_page' => $page, 'total' => 2, 'per_page' => 1]]]);
        });
        $this->actingAs($this->teacher())->getJson(route('record-options.students', ['group_code' => 'G1', 'level' => '1', 'term' => '2/2569']))
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.student_id', 'S1')->assertJsonPath('data.items.1.student_id', 'S2');
        Http::assertSentCount(4);
    }

    public function test_local_fallback_only_uses_selected_semester_and_year(): void
    {
        $user = $this->teacher();
        foreach (['1', '2'] as $semester) {
            $record = TeachingRecord::create(['id' => 'record-'.$semester, 'tambon' => 'own', 'year' => '2569', 'semester' => $semester, 'week' => 1, 'date' => '2026-10-25', 'level' => 'ประถมศึกษา', 'class_group_code' => 'G1', 'class_name' => 'กลุ่มเสนา', 'subject' => 'ภาษาไทย', 'created_by' => $user->id]);
            $record->attendance()->create(['student_id' => 'S'.$semester, 'full_name' => 'นักศึกษาภาค '.$semester, 'group_code' => 'G1', 'present' => false]);
        }
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($user)->getJson(route('record-options.students', ['group_code' => 'G1', 'level' => '1', 'year' => '2569', 'semester' => '2']))
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.student_id', 'S2')->assertJsonPath('data.source', 'local_attendance')->assertJsonPath('data.stale', true);
    }

    public function test_old_source_term_is_not_passed_off_as_new_semester(): void
    {
        config(['student_api.driver' => 'legacy', 'student_api.key' => 'legacy-key']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['student_id' => 'OLD', 'full_name' => 'นักศึกษาเก่า', 'group_code' => 'G1', 'level' => '1', 'academic_term' => '69/1', 'teachers' => [['username' => 'teacher']]]], 'meta' => ['latest_term' => '69/1']])]);
        $this->actingAs($this->teacher())->getJson(route('record-options.students', ['group_code' => 'G1', 'level' => '1', 'year' => '2569', 'semester' => '2']))
            ->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('data.error_code', 'student_term_unavailable')->assertJsonCount(0, 'data.items');
    }

    public function test_unlabelled_legacy_roster_cannot_be_assumed_to_match_requested_term(): void
    {
        config(['student_api.driver' => 'legacy', 'student_api.key' => 'legacy-key']);
        Http::fake(['*' => Http::response(['data' => [['student_id' => 'UNKNOWN', 'full_name' => 'ไม่ระบุภาคเรียน', 'group_code' => 'G1', 'level' => '1', 'teachers' => [['username' => 'teacher']]]]])]);
        $this->actingAs($this->teacher())->getJson(route('record-options.students', ['group_code' => 'G1', 'level' => '1', 'term' => '2/2569']))
            ->assertOk()->assertJsonPath('status', 'error')->assertJsonCount(0, 'data.items')->assertJsonPath('data.error_code', 'student_term_unavailable');
    }

    public function test_default_local_fallback_uses_latest_scoped_semester_without_old_rosters(): void
    {
        $user = $this->teacher();
        foreach (['1', '2'] as $semester) {
            $record = TeachingRecord::create(['id' => 'local-'.$semester, 'tambon' => 'own', 'year' => '2569', 'semester' => $semester, 'week' => 1, 'date' => '2026-10-25', 'level' => 'ประถมศึกษา', 'class_group_code' => 'G1', 'class_name' => 'กลุ่มเสนา', 'subject' => 'ภาษาไทย', 'created_by' => $user->id]);
            $record->attendance()->create(['student_id' => 'S'.$semester, 'full_name' => 'นักศึกษาภาค '.$semester, 'group_code' => 'G1', 'present' => false]);
        }
        Tambon::create(['id' => 'foreign', 'name' => 'อื่น']);
        $foreign = TeachingRecord::create(['id' => 'foreign-latest', 'tambon' => 'foreign', 'year' => '2570', 'semester' => '1', 'week' => 1, 'date' => '2027-05-01', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'created_by' => $user->id]);
        $foreign->attendance()->create(['student_id' => 'FOREIGN', 'full_name' => 'ต่างตำบล', 'present' => false]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($user)->get(route('students'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)->where('items.data.0.student_id', 'S2')->where('items.data.0.current_term', '2/2569')->where('source', 'local_attendance')->where('filters.term', '2/2569'));
    }

    public function test_roster_source_term_survives_scope_change_and_autosave_without_changing_attendance(): void
    {
        $user = $this->teacher();
        $payload = ['schema_version' => 1, 'scope' => ['tambon' => 'own', 'year' => '2569', 'semester' => '1', 'date' => '2026-10-25', 'week' => 1], 'levels' => ['1' => [
            'subject' => 'ภาษาไทย', 'attendance_mode' => 'roster', 'attendance' => [
                ['student_id' => 'S1', 'full_name' => 'ผู้มาเรียน', 'present' => true, 'learning_method' => 'พบกลุ่ม'],
                ['student_id' => 'S2', 'full_name' => 'ผู้ไม่มาเรียน', 'present' => false, 'learning_method' => null],
            ],
        ]]];
        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()
            ->assertJsonPath('data.draft.payload.levels.1.attendance_term', '1/2569');
        $payload = $created->json('data.draft.payload');
        $payload['scope']['semester'] = '2';
        $draftId = $created->json('data.draft.id');
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $this->getJson(route('teaching-drafts.show', $draftId))->assertOk()
            ->assertJsonPath('data.draft.payload.scope.semester', '2')
            ->assertJsonPath('data.draft.payload.levels.1.attendance_term', '1/2569')
            ->assertJsonPath('data.draft.payload.levels.1.attendance.0.present', true)
            ->assertJsonPath('data.draft.payload.levels.1.attendance.1.present', false);
        $payload['levels']['1']['attendance_term'] = 'token-or-unknown-source';
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 2, 'payload' => $payload])->assertUnprocessable()
            ->assertJsonValidationErrors('payload.levels.1.attendance_term');
    }

    public function test_directory_filters_and_normalizes_nested_sdl_students_without_sensitive_fields(): void
    {
        $this->fakeSdl();
        $this->actingAs($this->teacher())->get(route('students', ['term' => '2/2569']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.student_id', 'NEW')->where('items.data.0.current_term', '2/2569')->where('studentMeta.driver', 'sdl')->where('filters.term', '2/2569')->missing('items.data.0.academic'));
    }
}
