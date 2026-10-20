<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_plain_password_is_upgraded_on_login(): void
    {
        User::query()->create([
            'id' => 'u1', 'name' => 'ครู', 'username' => 'legacy', 'password' => 'old-password',
            'role' => 'tambon', 'tambon' => '1214120001',
        ]);

        $this->post('/login', ['username' => 'legacy', 'password' => 'old-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertNotSame('old-password', User::query()->findOrFail('u1')->password);
    }
}
