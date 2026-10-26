<?php

namespace Tests\Feature;

use App\Models\StudentApiConnection;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\StudentApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SubjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['student_api' => ['driver' => 'sdl', 'url' => StudentApiService::SDL_URL, 'token' => 'fixture-private-token', 'key' => '', 'fallback_urls' => []]]);
    }

    private function district(): User
    {
        return User::create(['id' => 'district', 'username' => 'district', 'name' => 'ผู้ดูแลทดสอบ', 'role' => 'district', 'password' => 'fixture-password']);
    }

    private function teacher(string $tambon = 't1'): User
    {
        Tambon::firstOrCreate(['id' => $tambon], ['name' => 'ตำบล '.$tambon]);

        return User::firstOrCreate(['id' => 'teacher-'.$tambon], ['username' => 'teacher-'.$tambon, 'name' => 'ครู '.$tambon, 'role' => 'tambon', 'tambon' => $tambon, 'password' => 'fixture-password']);
    }

    private function history(User $creator, string $id, string $code, string $subject, string $semester = '2', string $tambon = 't1'): TeachingRecord
    {
        Tambon::firstOrCreate(['id' => $tambon], ['name' => 'ตำบล '.$tambon]);

        return TeachingRecord::create([
            'id' => $id, 'created_by' => $creator->id, 'tambon' => $tambon,
            'week' => 1, 'date' => '2026-10-26', 'level' => 'ประถมศึกษา',
            'semester' => $semester, 'year' => '2569', 'subject' => $subject,
            'subject_code' => $code, 'total' => 1, 'present' => 0,
        ]);
    }

    private function fakeWorkspace(array $subjects = [], int $subjectStatus = 200): void
    {
        Http::fake(function (Request $request) use ($subjects, $subjectStatus) {
            if (str_contains($request->url(), '/subjects')) {
                return Http::response(['data' => $subjects, 'meta' => ['term' => $request['term'], 'total' => count($subjects)], 'password' => 'remote-private-value'], $subjectStatus);
            }
            if (str_contains($request->url(), '/teachers')) {
                return Http::response(['data' => [
                    ['username' => 'teacher-t1', 'assigned_groups' => ['G1']],
                    ['username' => 'teacher-t2', 'assigned_groups' => ['G2']],
                ], 'meta' => ['pagination' => ['last_page' => 1, 'total' => 2]]]);
            }

            $term = $request['term'] ?? '2/2569';

            return Http::response(['data' => [
                ['code' => 'S1', 'name' => ['full_name' => 'นักศึกษาทดสอบหนึ่ง'], 'current_term' => $term, 'level' => ['id' => 1], 'group' => ['code' => 'G1', 'name' => 'กลุ่มหนึ่ง']],
                ['code' => 'S2', 'name' => ['full_name' => 'นักศึกษาทดสอบสอง'], 'current_term' => $term, 'level' => ['id' => 2], 'group' => ['code' => 'G2', 'name' => 'กลุ่มสอง']],
            ], 'meta' => ['pagination' => ['last_page' => 1, 'total' => 2], 'filter_options' => ['terms' => [['value' => '2/2569'], ['value' => '1/2569']]]]]);
        });
    }

    public function test_catalogue_normalizes_only_safe_metadata_and_fetches_a_large_catalogue_once(): void
    {
        $rows = array_map(fn (int $number): array => ['code' => sprintf('SUB%03d', $number), 'name' => 'วิชา '.$number, 'student_count' => 30, 'password' => 'remote-private-value', 'level' => 3], range(1, 150));
        $rows[] = ['code' => ' sub001 ', 'name' => 'ชื่อซ้ำ'];
        $rows[] = ['code' => '', 'name' => 'ไม่มีรหัส'];
        $rows[] = ['code' => ['invalid'], 'name' => 'รหัสผิดรูปแบบ'];
        $rows[] = ['code' => 'TOO-LONG', 'name' => str_repeat('ก', 151)];
        Http::fake(['*' => Http::response(['data' => $rows, 'meta' => ['term' => '2/2569', 'total' => 150]])]);

        $subjects = app(StudentApiService::class)->subjects('2/2569');

        $this->assertCount(150, $subjects);
        $this->assertSame(['subject' => 'วิชา 1', 'subject_code' => 'SUB001', 'level' => '', 'source' => 'sdl'], $subjects->first());
        foreach ($subjects as $subject) {
            $this->assertSame(['subject', 'subject_code', 'level', 'source'], array_keys($subject));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/SDL_school/api/v1/integrations/student-data/subjects'
            && $request['term'] === '2/2569' && ! isset($request['page'])
            && $request->hasHeader('Authorization', 'Bearer fixture-private-token')
            && $request->hasHeader('X-Student-Data-Token', 'fixture-private-token')
            && ! str_contains($request->url(), 'fixture-private-token'));
    }

    public function test_catalogue_cache_is_separated_by_term_and_refresh_bypasses_it(): void
    {
        Http::fake(fn (Request $request) => Http::response(['data' => [['code' => $request['term'] === '1/2569' ? 'TERM-1' : 'TERM-2', 'name' => 'วิชาภาคเรียน']], 'meta' => ['term' => $request['term']]]));

        $this->assertSame('TERM-1', app(StudentApiService::class)->subjects('1/2569')->first()['subject_code']);
        $this->assertSame('TERM-2', app(StudentApiService::class)->subjects('2/2569')->first()['subject_code']);
        app(StudentApiService::class)->subjects('1/2569');
        app(StudentApiService::class)->subjects('2/2569');
        Http::assertSentCount(2);
        app(StudentApiService::class)->subjects('2/2569', true);
        Http::assertSentCount(3);
    }

    public function test_failed_or_mismatched_payloads_are_not_cached_and_a_retry_can_recover(): void
    {
        $invalid = [
            [['data' => [], 'meta' => ['term' => '1/2569']], 200],
            [['data' => [], 'meta' => []], 200],
            [['data' => ['items' => []], 'meta' => ['term' => '2/2569']], 200],
            [['data' => [['code' => '', 'name' => 'ผิดรูปแบบ']], 'meta' => ['term' => '2/2569']], 200],
            [['success' => false, 'data' => [], 'meta' => ['term' => '2/2569']], 200],
            [['message' => 'remote-private-value'], 401],
        ];
        $sequence = Http::sequence();
        foreach ($invalid as [$payload, $status]) {
            $sequence->push($payload, $status)->push(['data' => [['code' => 'RECOVERED', 'name' => 'โหลดใหม่สำเร็จ']], 'meta' => ['term' => '2/2569']]);
        }
        Http::fake(['*' => $sequence]);

        foreach ($invalid as $_) {
            try {
                app(StudentApiService::class)->subjects('2/2569');
                $this->fail('Invalid subject catalogue must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('remote-private-value', $exception->getMessage());
            }
            $this->assertSame('RECOVERED', app(StudentApiService::class)->subjects('2/2569')->first()['subject_code']);
            Cache::flush();
        }
        Http::assertSentCount(count($invalid) * 2);
    }

    public function test_a_valid_empty_catalogue_is_cached_without_reusing_another_terms_subjects(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['term' => '2/2569', 'total' => 0]])]);

        $this->assertTrue(app(StudentApiService::class)->subjects('2/2569')->isEmpty());
        $this->assertTrue(app(StudentApiService::class)->subjects('2/2569')->isEmpty());
        Http::assertSentCount(1);
    }

    public function test_encrypted_connection_rotation_cannot_reuse_previous_credentials_catalogue_cache(): void
    {
        StudentApiConnection::create(['id' => 1, 'token' => Crypt::encryptString('fixture-old-token'), 'latest_term' => '2/2569']);
        Http::fake(fn (Request $request) => Http::response(['data' => [['code' => $request->hasHeader('Authorization', 'Bearer fixture-new-token') ? 'NEW' : 'OLD', 'name' => 'วิชาทดสอบ']], 'meta' => ['term' => '2/2569']]));

        $this->assertSame('OLD', app(StudentApiService::class)->subjects('2/2569')->first()['subject_code']);
        StudentApiConnection::whereKey(1)->update(['token' => Crypt::encryptString('fixture-new-token')]);
        $this->assertSame('NEW', app(StudentApiService::class)->subjects('2/2569')->first()['subject_code']);
        Http::assertSentCount(2);
    }

    public function test_unsupported_terms_and_legacy_configuration_never_send_catalogue_requests(): void
    {
        foreach (['3/2569', '', 'all'] as $term) {
            try {
                app(StudentApiService::class)->subjects($term);
                $this->fail('A specific supported SDL term is required.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        config(['student_api.driver' => 'legacy']);
        $this->expectException(RuntimeException::class);
        try {
            app(StudentApiService::class)->subjects('2/2569');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_workspace_merges_api_first_and_keeps_history_scoped_to_tambon_and_term(): void
    {
        $teacher = $this->teacher();
        $otherTeacher = $this->teacher('t2');
        $this->history($teacher, 'duplicate', 'api1', 'ชื่อเก่าจากประวัติ');
        $this->history($teacher, 'own-history', 'HISTORY', 'วิชาของตำบล');
        $this->history($teacher, 'previous-term', 'OLDTERM', 'วิชาภาคเรียนก่อน', '1');
        $this->history($otherTeacher, 'other-history', 'OTHER', 'วิชาประวัติตำบลอื่น', '2', 't2');
        $this->fakeWorkspace([['code' => 'API1', 'name' => 'วิชาจาก SDL', 'student_count' => 99, 'citizen_id' => 'remote-private-value']]);

        foreach ([$teacher, $this->district()] as $user) {
            $response = $this->actingAs($user)->getJson(route('record-options.workspace', ['term' => '2/2569', 'tambon' => 't1']))
                ->assertOk()->assertJsonPath('data.subject_source', 'sdl')->assertJsonPath('data.subject_term', '2/2569')
                ->assertJsonPath('data.subject_error', null)->assertJsonCount(2, 'data.subjects')
                ->assertJsonPath('data.subjects.0.subject', 'วิชาจาก SDL')->assertJsonPath('data.subjects.0.level', '')
                ->assertJsonPath('data.subjects.1.subject_code', 'HISTORY')
                ->assertDontSee('ชื่อเก่าจากประวัติ')->assertDontSee('OLDTERM')->assertDontSee('OTHER')
                ->assertDontSee('fixture-private-token')->assertDontSee('remote-private-value');
            $this->assertArrayNotHasKey('student_count', $response->json('data.subjects.0'));
            $this->assertSame(['G1'], array_column($response->json('data.groups'), 'group_code'));
        }
    }

    public function test_catalogue_failure_keeps_current_term_history_and_returns_only_a_safe_notice(): void
    {
        $teacher = $this->teacher();
        $this->history($teacher, 'current', 'CURRENT', 'รายวิชาภาคเรียนนี้');
        $this->history($teacher, 'old', 'OLD', 'รายวิชาภาคเรียนก่อน', '1');
        $this->fakeWorkspace([], 401);

        $this->actingAs($teacher)->getJson(route('record-options.workspace', ['term' => '2/2569']))
            ->assertOk()->assertJsonPath('data.subject_source', 'history')->assertJsonPath('data.subject_term', '2/2569')
            ->assertJsonCount(1, 'data.subjects')->assertJsonPath('data.subjects.0.subject_code', 'CURRENT')
            ->assertJsonPath('data.subject_error', fn ($message): bool => is_string($message) && str_contains($message, '2/2569'))
            ->assertDontSee('remote-private-value')->assertDontSee('fixture-private-token')->assertDontSee('OLD');
    }

    public function test_empty_catalogue_preserves_manual_entry_and_workspace_requires_authentication(): void
    {
        $this->getJson(route('record-options.workspace', ['term' => '2/2569']))->assertUnauthorized();
        Http::assertNothingSent();
        $this->fakeWorkspace();

        $this->actingAs($this->teacher())->getJson(route('record-options.workspace', ['term' => '2/2569']))
            ->assertOk()->assertJsonCount(0, 'data.subjects')->assertJsonPath('data.subject_source', 'sdl')
            ->assertJsonPath('data.subject_error', null)->assertJsonPath('data.subject_term', '2/2569');
    }

    public function test_opening_existing_draft_only_offers_catalogue_without_rewriting_subject_or_attendance(): void
    {
        $teacher = $this->teacher();
        $this->fakeWorkspace([['code' => 'NEW', 'name' => 'วิชาแนะนำใหม่']]);
        $payload = ['schema_version' => 1, 'scope' => ['tambon' => 't1', 'year' => '2569', 'semester' => '2', 'date' => '2026-10-26', 'week' => 1], 'enabled_level_codes' => ['1'], 'active_level_code' => '1', 'levels' => ['1' => [
            'subject' => 'ชื่อรายวิชาเดิมที่ผู้ใช้แก้ไข', 'subject_code' => 'MANUAL', 'class_group_code' => 'G1', 'class_name' => 'กลุ่มหนึ่ง',
            'result' => 'ผลเดิม', 'problem' => 'ปัญหาเดิม', 'suggestion' => 'ข้อเสนอเดิม', 'attendance_mode' => 'roster', 'attendance_reviewed' => false,
            'attendance_term' => '2/2569', 'attendance' => [['student_id' => 'S1', 'full_name' => 'ผู้เรียนเดิม', 'group_code' => 'G1', 'class_name' => 'กลุ่มหนึ่ง', 'present' => false, 'learning_method' => 'พบกลุ่ม']], 'image_ids' => [],
        ]]];
        $id = $this->actingAs($teacher)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $draft = TeachingDraft::findOrFail($id);
        $storedPayload = $draft->getRawOriginal('payload');
        $lockVersion = $draft->lock_version;

        $this->get(route('records.create', ['draft' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('subjects.0.subject_code', 'NEW')
            ->where('workspace.draft.payload.levels.1.subject', 'ชื่อรายวิชาเดิมที่ผู้ใช้แก้ไข')
            ->where('workspace.draft.payload.levels.1.subject_code', 'MANUAL')
            ->where('workspace.draft.payload.levels.1.attendance.0.present', false));
        $this->assertSame($storedPayload, $draft->fresh()->getRawOriginal('payload'));
        $this->assertSame($lockVersion, $draft->fresh()->lock_version);
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertDatabaseCount('weekly_submissions', 0);
    }
}
