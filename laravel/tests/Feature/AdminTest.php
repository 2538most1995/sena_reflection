<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tambon;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_district_can_open_admin_and_create_users(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $tambon = User::create(['id' => 'u1', 'name' => 'ครู', 'username' => 'teacher', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        $district = User::create(['id' => 'u0', 'name' => 'อำเภอ', 'username' => 'admin', 'password' => Hash::make('secret'), 'role' => 'district']);
        $this->actingAs($tambon)->get(route('admin'))->assertForbidden();
        $this->actingAs($district)->get(route('admin'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/index')->has('users', 2)->has('tambons', 1));
        $this->actingAs($district)->post(route('admin.users.store'), ['name' => 'ครูใหม่', 'username' => 'new', 'password' => 'password1', 'role' => 'tambon', 'tambon' => 't1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'new', 'tambon' => 't1']);
        $this->actingAs($district)->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('profile/edit')->where('user.id', $district->id));
    }

    public function test_district_can_preview_pdf_settings_before_saving(): void
    {
        $district = User::create(['id' => 'u0', 'name' => 'อำเภอ', 'username' => 'admin', 'password' => Hash::make('secret'), 'role' => 'district']);
        $this->mock(LegacyPrintService::class)
            ->shouldReceive('settingsPreviewHtml')
            ->once()
            ->andReturn('<!doctype html><html lang="th"><body>preview</body></html>');

        $this->actingAs($district)->postJson(route('admin.pdf-preview'), [
            'settings' => ['sig1_offset' => '12', 'sig1_offset_y' => '-4'],
        ])->assertOk()->assertHeader('content-type', 'text/html; charset=UTF-8')->assertSee('preview');
    }

    public function test_district_can_save_page_visibility_and_individual_image_layout(): void
    {
        $district = User::create(['id' => 'u0', 'name' => 'อำเภอ', 'username' => 'admin', 'password' => Hash::make('secret'), 'role' => 'district']);

        $this->actingAs($district)->put(route('admin.settings'), [
            'settings' => [
                'show_image_page' => '0',
                'show_attendance_page' => '1',
                'image_1_width_px' => '420',
                'image_1_height_px' => '280',
                'image_1_offset_x' => '34',
                'image_1_offset_y' => '-16',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['setting_key' => 'show_image_page', 'setting_value' => '0']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'show_attendance_page', 'setting_value' => '1']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'image_1_width_px', 'setting_value' => '420']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'image_1_offset_y', 'setting_value' => '-16']);
        $this->assertSame('280', Setting::query()->where('setting_key', 'image_1_height_px')->value('setting_value'));
    }
}
