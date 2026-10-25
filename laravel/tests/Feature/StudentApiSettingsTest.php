<?php

namespace Tests\Feature;

use App\Models\StudentApiConnection;
use App\Models\User;
use App\Services\StudentApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentApiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'district'): User
    {
        return User::create(['id' => 'user-'.$role, 'name' => 'ผู้ทดสอบ', 'username' => 'test-'.$role, 'password' => 'secret', 'role' => $role]);
    }

    private function fakeConnection(int $teachersStatus = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/teachers')
            ? Http::response(['data' => [['username' => 'teacher', 'assigned_groups' => ['G1']]]], $teachersStatus)
            : Http::response(['data' => [], 'meta' => ['pagination' => ['total' => 794], 'filter_options' => ['terms' => [['value' => '2/2569']]]]]));
    }

    public function test_only_district_can_configure_or_probe_student_source(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->user('tambon'))->get(route('admin.student-api'))->assertForbidden();
        $this->postJson(route('admin.student-api.store'), ['token' => 'private-token'])->assertForbidden();
        $this->postJson(route('admin.student-api.test'), ['token' => 'private-token'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_valid_token_is_verified_encrypted_and_never_returned_to_browser(): void
    {
        $this->fakeConnection();
        $this->actingAs($this->user())->postJson(route('admin.student-api.store'), ['token' => 'private-token'])
            ->assertOk()->assertJsonPath('data.latest_term', '2/2569')->assertJsonPath('data.student_count', 794)->assertDontSee('private-token');
        $connection = StudentApiConnection::findOrFail(1);
        $this->assertNotSame('private-token', $connection->token);
        $this->assertSame('private-token', Crypt::decryptString($connection->token));
        $this->assertArrayNotHasKey('token', $connection->toArray());
        $this->get(route('admin.student-api'))->assertOk()->assertDontSee('private-token')->assertInertia(fn (Assert $page) => $page
            ->where('integration.driver', 'sdl')->where('integration.token_configured', true)->where('integration.latest_term', '2/2569'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), StudentApiService::SDL_URL) && $request->hasHeader('Authorization', 'Bearer private-token') && ! str_contains($request->url(), 'private-token'));
        $this->postJson(route('admin.student-api.test'), [])->assertOk();
        $this->assertSame('private-token', Crypt::decryptString(StudentApiConnection::findOrFail(1)->token));
    }

    public function test_test_action_never_persists_token_and_failed_replacement_preserves_previous_token(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new-private-token')
            ? Http::response(['message' => 'invalid private-token'], 401)
            : Http::response(['data' => [], 'meta' => ['filter_options' => ['terms' => [['value' => '2/2569']]]]]));
        $this->actingAs($this->user())->postJson(route('admin.student-api.test'), ['token' => 'test-only-token'])->assertOk();
        $this->assertDatabaseCount('student_api_connections', 0);
        $this->postJson(route('admin.student-api.store'), ['token' => 'previous-token'])->assertOk();
        $this->postJson(route('admin.student-api.store'), ['token' => 'new-private-token'])->assertUnprocessable()->assertJsonValidationErrors('token')->assertDontSee('new-private-token')->assertDontSee('invalid private-token');
        $this->assertSame('previous-token', Crypt::decryptString(StudentApiConnection::findOrFail(1)->token));
    }

    public function test_missing_teacher_scope_ability_cannot_enable_sdl_connection(): void
    {
        $this->fakeConnection(403);
        $this->actingAs($this->user())->postJson(route('admin.student-api.store'), ['token' => 'student-only-token'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseCount('student_api_connections', 0);
    }

    public function test_changing_verified_token_cannot_reuse_previous_source_roster_cache(): void
    {
        config(['student_api' => ['driver' => 'sdl', 'url' => StudentApiService::SDL_URL, 'token' => 'previous-token', 'key' => '', 'fallback_urls' => []]]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $student = $request->hasHeader('Authorization', 'Bearer replacement-token') ? 'NEW' : 'OLD';
            $rows = str_contains($request->url(), '/teachers') ? [] : [['code' => $student, 'name' => ['full_name' => 'นักศึกษา '.$student], 'current_term' => '2/2569', 'level' => ['id' => 1]]];

            return Http::response(['data' => $rows, 'meta' => ['pagination' => ['last_page' => 1, 'total' => count($rows)]]]);
        });
        $this->actingAs($this->user())->get(route('students', ['term' => '2/2569']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.data.0.student_id', 'OLD'));
        $this->postJson(route('admin.student-api.store'), ['token' => 'replacement-token', 'url' => 'https://untrusted.example/students'])->assertOk();
        $this->get(route('students', ['term' => '2/2569']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.data.0.student_id', 'NEW'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'untrusted.example'));
    }

    public function test_status_url_strips_query_credentials_and_userinfo(): void
    {
        config(['student_api.url' => 'https://name:password@example.test/students?api_key=secret', 'student_api.driver' => 'legacy']);
        $this->actingAs($this->user())->get(route('admin.student-api'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('integration.url', 'https://example.test/students')->where('integration.token_configured', false));
    }
}
