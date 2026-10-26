<?php

namespace Tests\Feature\Students;

use App\Http\Middleware\AuthenticateStudentDataClient;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

require_once __DIR__.'/AuthenticateStudentDataClient.php';

final class ReflectionClientExpiryPatchTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/_reflection_client_expiry_patch_test';

    private District $district;

    private User $admin;

    private StudentApiClient $approved;

    private StudentApiClient $unapproved;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reflection_integration.non_expiring_client_ids' => []]);
        $this->district = District::query()->create(['name' => 'Fixture', 'code' => 'fixture', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id, 'disabled_at' => null]);
        $this->unapproved = $this->client(2, 'fixture-unapproved');
        $this->approved = $this->client(4, 'fixture-approved');

        Route::middleware(AuthenticateStudentDataClient::class)->get(self::ENDPOINT, fn (Request $request) => response()->json([
            'user_id' => $request->user()->id,
            'district_id' => $request->attributes->get('district_id'),
            'client_id' => $request->attributes->get('student_api_client_id'),
        ]));
    }

    private function client(int $id, string $token): StudentApiClient
    {
        $client = (new StudentApiClient)->forceFill([
            'id' => $id, 'name' => 'fixture-only-'.$id,
            'user_id' => $this->admin->id, 'district_id' => $this->district->id,
            'token_hash' => hash('sha256', $token),
            'abilities' => ['student-data:read', 'sena-care:teachers'],
            'expires_at' => null,
        ]);
        $client->save();

        return $client;
    }

    public function test_default_empty_allowlist_rejects_all_non_expiring_clients(): void
    {
        foreach (['fixture-unapproved', 'fixture-approved'] as $token) {
            $this->withToken($token)->getJson(self::ENDPOINT)->assertUnauthorized();
        }
    }

    public function test_only_the_reviewed_client_is_enabled_without_changing_others(): void
    {
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->withToken('fixture-unapproved')->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('fixture-approved')->getJson(self::ENDPOINT)->assertOk()
            ->assertExactJson(['user_id' => $this->admin->id, 'district_id' => $this->district->id, 'client_id' => 4]);
        self::assertNull($this->unapproved->fresh()->last_used_at);
        self::assertNotNull($this->approved->fresh()->last_used_at);
    }

    public function test_no_expiry_still_requires_approval_after_time_passes_and_can_be_revoked(): void
    {
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->travel(366)->days();
        $this->withToken('fixture-approved')->getJson(self::ENDPOINT)->assertOk();
        $this->approved->update(['revoked_at' => now()]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_dated_expiry_contract_is_preserved_including_for_allowlisted_id(): void
    {
        $this->unapproved->update(['expires_at' => now()->addDay()]);
        $this->withToken('fixture-unapproved')->getJson(self::ENDPOINT)->assertOk();
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->approved->update(['expires_at' => now()]);
        $this->withToken('fixture-approved')->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->approved->update(['expires_at' => now()->subSecond()]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_allowlist_cannot_bypass_missing_read_ability_or_disabled_issuer_or_district(): void
    {
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->withToken('fixture-approved');
        $this->approved->update(['abilities' => ['sena-care:teachers']]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->approved->update(['abilities' => ['student-data:read', 'sena-care:teachers']]);
        $this->admin->forceFill(['disabled_at' => now()])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->admin->forceFill(['disabled_at' => null])->save();
        $this->district->update(['is_active' => false]);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_allowlist_cannot_bypass_district_binding_browser_origin_or_override(): void
    {
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->withToken('fixture-approved');
        $this->getJson(self::ENDPOINT.'?district_id=99')->assertUnprocessable();
        $this->withHeader('X-District-Id', '99')->getJson(self::ENDPOINT)->assertUnprocessable();
        $this->withHeaders(['X-District-Id' => '', 'Origin' => 'https://example.test'])->getJson(self::ENDPOINT)->assertForbidden();
        $this->flushHeaders();
        $this->admin->forceFill(['district_id' => null])->save();
        $this->withToken('fixture-approved')->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_transport_fallback_remains_supported_for_the_approved_client(): void
    {
        config(['reflection_integration.non_expiring_client_ids' => [4]]);
        $this->withHeader('X-Student-Data-Token', 'fixture-approved')->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_malformed_allowlist_fails_closed(): void
    {
        foreach ([null, '4', ['4'], [true, 4.0, -4, 0]] as $ids) {
            config(['reflection_integration.non_expiring_client_ids' => $ids]);
            $this->withToken('fixture-approved')->getJson(self::ENDPOINT)->assertUnauthorized();
        }
    }
}
