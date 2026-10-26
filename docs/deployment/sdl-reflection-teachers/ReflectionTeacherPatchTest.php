<?php

namespace Tests\Feature\Students;

use App\Http\Controllers\Api\Integrations\ReflectionTeacherController;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

require_once __DIR__.'/ReflectionTeacherController.php';

final class ReflectionTeacherPatchTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/_reflection_teachers_patch_test';

    private District $district;

    private User $admin;

    private StudentApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::query()->create(['name' => 'Fixture', 'code' => 'fixture', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id, 'disabled_at' => null]);
        User::factory()->create([
            'username' => 'fixture.teacher', 'name' => 'Fixture Teacher',
            'role' => 'teacher', 'district_id' => $this->district->id, 'disabled_at' => null,
            'assigned_groups' => ['GROUP-1', ' GROUP-1 ', '', 99],
        ]);
        $this->client = StudentApiClient::query()->create([
            'name' => 'fixture-only', 'user_id' => $this->admin->id, 'district_id' => $this->district->id,
            'token_hash' => hash('sha256', 'fixture-token-only'),
            'abilities' => ['student-data:read', 'sena-care:teachers'], 'expires_at' => now()->addDay(),
        ]);

        // Deliberately simulate old middleware: authenticated user + district,
        // no student_api_client or student_api_client_id request attributes.
        $districtId = $this->district->id;
        Route::get(self::ENDPOINT, function (Request $request) use ($districtId) {
            $request->attributes->set('district_id', $districtId);

            return app(ReflectionTeacherController::class)($request);
        });
    }

    public function test_old_middleware_context_is_supported_with_exact_allowlisted_fields(): void
    {
        $other = District::query()->create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        User::factory()->create(['role' => 'teacher', 'district_id' => $other->id]);
        User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'disabled_at' => now()]);
        $response = $this->actingAs($this->admin)->withToken('fixture-token-only')
            ->getJson(self::ENDPOINT)->assertOk()
            ->assertExactJson([
                'data' => [['username' => 'fixture.teacher', 'name' => 'Fixture Teacher', 'assigned_groups' => ['GROUP-1']]],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1]],
            ]);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('Authorization, X-Student-Data-Token', $response->headers->get('Vary'));
    }

    public function test_token_header_fallback_is_supported(): void
    {
        $this->actingAs($this->admin)->withHeader('X-Student-Data-Token', 'fixture-token-only')
            ->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_guest_missing_and_wrong_tokens_fail_closed(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->actingAs($this->admin)->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('wrong-fixture-token')->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_both_explicit_read_abilities_are_required(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        foreach ([['student-data:read'], ['sena-care:teachers'], []] as $abilities) {
            $this->client->update(['abilities' => $abilities]);
            $this->getJson(self::ENDPOINT)->assertForbidden();
        }
    }

    public function test_expired_and_revoked_clients_are_rejected(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->client->update(['expires_at' => now()]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->client->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_client_without_expiry_remains_scoped_and_can_be_revoked(): void
    {
        $other = District::query()->create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        User::factory()->create(['role' => 'teacher', 'district_id' => $other->id]);
        $this->client->update(['expires_at' => null]);
        $this->actingAs($this->admin)->withToken('fixture-token-only');

        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'fixture.teacher');

        $this->client->update(['revoked_at' => now()]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_client_must_match_authenticated_user_and_current_district(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id]);
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->client->update(['user_id' => $otherAdmin->id]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->client->update(['user_id' => $this->admin->id, 'district_id' => $this->district->id + 100]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_disabled_admin_and_disabled_district_are_rejected(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->admin->forceFill(['disabled_at' => now()])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->admin->forceFill(['disabled_at' => null])->save();
        $this->district->update(['is_active' => false]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_admin_role_and_district_are_checked_even_without_middleware(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->admin->forceFill(['role' => 'teacher'])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->admin->forceFill(['role' => 'admin', 'district_id' => null])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_browser_origin_and_scope_override_are_rejected(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->getJson(self::ENDPOINT.'?district_id=99')->assertUnprocessable();
        $this->withHeader('X-District-Id', '99')->getJson(self::ENDPOINT)->assertUnprocessable();
        $this->withHeader('Origin', 'https://example.test')->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_pagination_is_bounded_and_out_of_range_page_is_empty(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->getJson(self::ENDPOINT.'?per_page=101')->assertUnprocessable();
        $this->getJson(self::ENDPOINT.'?page=0')->assertUnprocessable();
        $this->getJson(self::ENDPOINT.'?per_page=1&page=99')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.current_page', 99);
    }

    public function test_mutating_methods_do_not_exist(): void
    {
        $this->actingAs($this->admin)->withToken('fixture-token-only');
        $this->postJson(self::ENDPOINT)->assertStatus(405);
        $this->patchJson(self::ENDPOINT)->assertStatus(405);
        $this->deleteJson(self::ENDPOINT)->assertStatus(405);
    }
}
