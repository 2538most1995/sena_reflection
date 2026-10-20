<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecordCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_create_page_and_student_picker_use_safe_api_options(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        TeachingRecord::create([
            'id' => 'history-1', 'tambon' => 't1', 'week' => 1, 'date' => '2026-08-01',
            'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569',
            'subject' => 'ภาษาไทย', 'subject_code' => 'พท11001', 'total' => 1, 'present' => 1, 'created_by' => 'u1',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'view=groups')) {
                return Http::response(['success' => true, 'data' => [[
                    'group_code' => 'G01', 'class_name' => 'กลุ่มเสนา', 'level' => '1', 'level_name' => 'ประถมศึกษา',
                    'teachers' => [['username' => 'teacher1', 'name' => 'ครูเสนา']],
                ]], 'meta' => ['view' => 'groups']]);
            }

            return Http::response(['success' => true, 'data' => [[
                'student_id' => 'S001', 'full_name' => 'นักศึกษา ทดสอบ', 'group_code' => 'G01', 'class_name' => 'กลุ่มเสนา',
                'password' => 'must-not-leak', 'teachers' => [['username' => 'teacher1', 'name' => 'ครูเสนา']],
            ]]]);
        });

        $this->actingAs($user)->get(route('records.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('records/create')
                ->where('groups.0.group_code', 'G01')
                ->where('subjects.0.subject_code', 'พท11001'));

        $this->getJson(route('record-options.students', ['group_code' => 'G01', 'level' => '1']))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.items.0.student_id', 'S001')
            ->assertJsonMissingPath('data.items.0.password');
    }

    public function test_workspace_options_normalize_thai_fallback_levels_to_canonical_codes(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        $record = TeachingRecord::create([
            'id' => 'fallback-1', 'tambon' => 't1', 'week' => 1, 'date' => '2026-10-01',
            'level' => 'มัธยมศึกษาตอนต้น', 'semester' => '1', 'year' => '2569',
            'subject' => 'คณิตศาสตร์', 'subject_code' => 'พค21001', 'class_group_code' => 'G02',
            'class_name' => 'กลุ่มเสนา', 'total' => 1, 'present' => 1, 'created_by' => 'u1',
        ]);
        $record->attendance()->create([
            'student_id' => 'S002', 'full_name' => 'นักศึกษาสำรอง', 'group_code' => 'G02',
            'class_name' => 'กลุ่มเสนา', 'present' => true,
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        $this->actingAs($user)->getJson(route('record-options.workspace'))
            ->assertOk()
            ->assertJsonPath('data.groups.0.level', '2')
            ->assertJsonPath('data.groups.0.level_code', '2')
            ->assertJsonPath('data.groups.0.level_name', 'มัธยมศึกษาตอนต้น')
            ->assertJsonPath('data.subjects.0.level_code', '2')
            ->assertJsonPath('data.stale', true);
    }

    public function test_district_workspace_options_are_scoped_to_the_selected_tambon(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        Tambon::create(['id' => 't2', 'name' => 'สามกอ']);
        User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        User::create(['id' => 'u2', 'name' => 'ครูสามกอ', 'username' => 'teacher2', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't2']);
        $district = User::create(['id' => 'd1', 'name' => 'ผู้ดูแล', 'username' => 'district', 'password' => Hash::make('secret'), 'role' => 'district']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['group_code' => 'G01', 'class_name' => 'กลุ่มเสนา', 'level_name' => 'ประถมศึกษา', 'teachers' => [['username' => 'teacher1', 'name' => 'ครูเสนา']]],
            ['group_code' => 'G02', 'class_name' => 'กลุ่มสามกอ', 'level_name' => 'มัธยมศึกษาตอนต้น', 'teachers' => [['username' => 'teacher2', 'name' => 'ครูสามกอ']]],
        ]])]);

        $this->actingAs($district)->getJson(route('record-options.workspace', ['tambon' => 't1']))
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.group_code', 'G01')
            ->assertJsonPath('data.groups.0.level_code', '1');
    }

    public function test_student_picker_fetches_all_pages_and_rejects_explicitly_different_tambon_rows(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $page = (int) ($request['page'] ?? 1);

            return Http::response([
                'success' => true,
                'data' => [
                    ['student_id' => 'S'.$page, 'full_name' => 'ผู้เรียน '.$page, 'group_code' => 'G01', 'level' => '1',
                        'tambon' => 't1', 'teachers' => [['username' => 'teacher1']]],
                    ['student_id' => 'X'.$page, 'full_name' => 'ผู้เรียนต่างตำบล', 'group_code' => 'G01', 'level' => '1',
                        'tambon' => 't2', 'teachers' => [['username' => 'teacher1']]],
                ],
                'meta' => ['pagination' => ['page' => $page, 'last_page' => 2, 'per_page' => 2, 'total' => 4]],
            ]);
        });

        $this->actingAs($user)->getJson(route('record-options.students', ['group_code' => 'G01', 'level' => '1']))
            ->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.student_id', 'S1')
            ->assertJsonPath('data.items.1.student_id', 'S2')
            ->assertJsonMissing(['full_name' => 'ผู้เรียนต่างตำบล']);

        Http::assertSentCount(2);
    }
}
