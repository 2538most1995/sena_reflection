<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_settings_save_uses_a_bounded_batch_and_preserves_unrelated_scoped_values(): void
    {
        $district = User::create(['id' => 'district-settings', 'name' => 'อำเภอ', 'username' => 'district-settings', 'password' => Hash::make('fixture-only'), 'role' => 'district']);
        Setting::query()->create(['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อเดิม']);
        Setting::query()->create(['setting_key' => 'user:teacher:sig1_name', 'setting_value' => 'ชื่อส่วนตัว']);
        Cache::put('pdf:settings', ['cached' => true]);
        Cache::put('pdf:settings:v2', ['cached' => true]);

        DB::enableQueryLog();
        $response = $this->actingAs($district)->put(route('admin.settings'), [
            'settings' => ['sig1_name' => 'ชื่อใหม่', 'pdf_font_size_pt' => '18', 'image_gap_px' => '24'],
        ]);
        $writes = collect(DB::getQueryLog())->filter(static fn (array $query): bool => preg_match('/^\s*(insert|update|replace)\b/i', $query['query']) === 1 && str_contains($query['query'], 'settings'));
        DB::disableQueryLog();

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(1, $writes, 'Saving document settings must not write every setting separately.');
        $this->assertDatabaseHas('settings', ['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อใหม่']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'pdf_font_size_pt', 'setting_value' => '18']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'image_gap_px', 'setting_value' => '24']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'user:teacher:sig1_name', 'setting_value' => 'ชื่อส่วนตัว']);
        $this->assertFalse(Cache::has('pdf:settings'));
        $this->assertFalse(Cache::has('pdf:settings:v2'));
    }

    public function test_invalid_legacy_settings_do_not_partially_overwrite_saved_values(): void
    {
        $district = User::create(['id' => 'district-settings', 'name' => 'อำเภอ', 'username' => 'district-settings', 'password' => Hash::make('fixture-only'), 'role' => 'district']);
        Setting::query()->create(['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อเดิม']);

        foreach ([str_repeat('x', 10001), ['nested' => 'invalid']] as $invalidValue) {
            $this->actingAs($district)->postJson('/api/settings.php', ['sig1_name' => 'ห้ามบันทึกบางส่วน', 'invalid' => $invalidValue])
                ->assertOk()->assertJsonPath('status', 'error')
                ->assertJsonPath('message', 'ข้อมูลการตั้งค่าไม่ถูกต้องหรือมีขนาดเกินกำหนด');
            $this->assertDatabaseHas('settings', ['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อเดิม']);
            $this->assertDatabaseMissing('settings', ['setting_key' => 'invalid']);
        }
    }

    public function test_legacy_settings_batch_preserves_teacher_scope_and_filters_global_keys(): void
    {
        Tambon::create(['id' => 'teacher-scope', 'name' => 'ตำบลทดสอบ']);
        $teacher = User::create(['id' => 'teacher-settings', 'name' => 'ครู', 'username' => 'teacher-settings', 'password' => Hash::make('fixture-only'), 'role' => 'tambon', 'tambon' => 'teacher-scope']);
        Setting::query()->create(['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อส่วนกลาง']);
        Setting::query()->create(['setting_key' => 'sig2_name', 'setting_value' => 'หัวหน้างาน']);
        Setting::query()->create(['setting_key' => 'user:teacher-settings:sig1_name', 'setting_value' => 'ชื่อครูเดิม']);

        $this->actingAs($teacher)->postJson('/api/settings.php', [
            'sig1_name' => 'ชื่อครูใหม่', 'sig1_offset' => '12', 'sig2_name' => 'ห้ามเขียนทับส่วนกลาง',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('settings', ['setting_key' => 'user:teacher-settings:sig1_name', 'setting_value' => 'ชื่อครูใหม่']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'user:teacher-settings:sig1_offset', 'setting_value' => '12']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'sig1_name', 'setting_value' => 'ชื่อส่วนกลาง']);
        $this->assertDatabaseHas('settings', ['setting_key' => 'sig2_name', 'setting_value' => 'หัวหน้างาน']);
        $this->assertDatabaseMissing('settings', ['setting_key' => 'user:teacher-settings:sig2_name']);
    }

    public function test_admin_and_legacy_deletion_preserve_owners_of_active_or_paused_drafts(): void
    {
        $district = User::create(['id' => 'district-delete', 'name' => 'อำเภอ', 'username' => 'district-delete', 'password' => Hash::make('fixture-only'), 'role' => 'district']);
        Tambon::create(['id' => 'draft-scope', 'name' => 'ตำบลทดสอบ']);

        foreach (['active', 'paused'] as $status) {
            $teacher = User::create(['id' => 'teacher-'.$status, 'name' => 'ครู', 'username' => 'teacher-'.$status, 'password' => Hash::make('fixture-only'), 'role' => 'tambon', 'tambon' => 'draft-scope']);
            $draft = TeachingDraft::query()->create(['user_id' => $teacher->id, 'tambon' => 'draft-scope', 'status' => $status, 'payload' => ['result' => 'งานส่วนตัวที่ยังไม่ส่ง']]);

            $this->actingAs($district)->delete(route('admin.users.destroy', $teacher))
                ->assertRedirect()->assertSessionHasErrors(['user' => 'บัญชีนี้ยังมีร่างที่กำลังใช้งานหรือพักไว้ จึงลบไม่ได้']);
            $this->actingAs($district)->deleteJson('/api/users.php?id='.$teacher->id)
                ->assertOk()->assertJsonPath('status', 'error')
                ->assertJsonPath('message', 'บัญชีนี้ยังมีร่างที่กำลังใช้งานหรือพักไว้ จึงลบไม่ได้');
            $this->assertDatabaseHas('users', ['id' => $teacher->id]);
            $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'user_id' => $teacher->id, 'status' => $status]);
        }
    }

    public function test_admin_and_legacy_deletion_preserve_tambons_used_only_by_active_or_paused_drafts(): void
    {
        $district = User::create(['id' => 'district-delete', 'name' => 'อำเภอ', 'username' => 'district-delete', 'password' => Hash::make('fixture-only'), 'role' => 'district']);

        foreach (['active', 'paused'] as $status) {
            $tambon = Tambon::create(['id' => 'scope-'.$status, 'name' => 'ตำบลทดสอบ']);
            $draft = TeachingDraft::query()->create(['user_id' => $district->id, 'tambon' => $tambon->id, 'status' => $status, 'payload' => []]);

            $this->actingAs($district)->delete(route('admin.tambons.destroy', $tambon))
                ->assertRedirect()->assertSessionHasErrors(['tambon']);
            $this->actingAs($district)->deleteJson('/api/tambons.php?id='.$tambon->id)
                ->assertOk()->assertJsonPath('status', 'error');
            $this->assertDatabaseHas('tambons', ['id' => $tambon->id]);
            $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'tambon' => $tambon->id, 'status' => $status]);
        }
    }

    public function test_unused_user_and_tambon_can_still_be_deleted(): void
    {
        $district = User::create(['id' => 'district-delete', 'name' => 'อำเภอ', 'username' => 'district-delete', 'password' => Hash::make('fixture-only'), 'role' => 'district']);
        $tambon = Tambon::create(['id' => 'unused-scope', 'name' => 'ตำบลว่าง']);
        $teacher = User::create(['id' => 'unused-teacher', 'name' => 'ครู', 'username' => 'unused-teacher', 'password' => Hash::make('fixture-only'), 'role' => 'tambon', 'tambon' => $tambon->id]);

        $this->actingAs($district)->delete(route('admin.users.destroy', $teacher))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
        $this->actingAs($district)->deleteJson('/api/tambons.php?id='.$tambon->id)->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('tambons', ['id' => $tambon->id]);
    }
}
