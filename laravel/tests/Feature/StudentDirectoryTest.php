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

class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_tambon_only_sees_its_teacher_groups(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['class_name' => 'กลุ่มเสนา', 'teachers' => [['username' => 'teacher1', 'name' => 'ครูเสนา']], 'students' => [['id' => 1]]],
            ['class_name' => 'กลุ่มอื่น', 'teachers' => [['username' => 'other', 'name' => 'ครูอื่น']], 'students' => [['id' => 2]]],
        ]])]);
        $this->actingAs($user)->get(route('students'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('students/index')->has('items.data', 1)->where('items.data.0.class_name', 'กลุ่มเสนา'));
        Http::assertSent(fn ($request) => ! isset($request['view']));
    }

    public function test_student_api_nested_payload_is_normalized_without_sensitive_fields(): void
    {
        $user = User::create(['id' => 'd1', 'name' => 'ผู้ดูแล', 'username' => 'district', 'password' => Hash::make('secret'), 'role' => 'district']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['students' => [[
            'student_id' => 'S001', 'full_name' => 'นักศึกษา ทดสอบ', 'citizen_id' => '1111111111111',
            'class_name' => 'กลุ่มเสนา', 'level_name' => 'ประถมศึกษา',
        ]]]])]);

        $this->actingAs($user)->get(route('students'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('students/index')
                ->where('items.data.0.student_id', 'S001')
                ->where('items.data.0.full_name', 'นักศึกษา ทดสอบ')
                ->missing('items.data.0.citizen_id'));
    }

    public function test_local_attendance_is_used_when_student_api_is_unavailable(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher1', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        $record = TeachingRecord::create([
            'id' => 'record-local-1', 'tambon' => 't1', 'week' => 1, 'date' => '2026-08-30',
            'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'ภาษาไทย',
            'class_name' => 'กลุ่มเสนา', 'total' => 1, 'present' => 1, 'created_by' => 'u1',
        ]);
        $record->attendance()->create(['student_id' => 'LOCAL01', 'full_name' => 'นักศึกษาสำรอง', 'present' => true]);
        Http::fake(['*' => Http::response([], 503)]);

        $this->actingAs($user)->get(route('students'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('students/index')
                ->where('items.data.0.full_name', 'นักศึกษาสำรอง')
                ->where('source', 'local_attendance')
                ->where('notice', 'Student API ไม่ตอบสนอง ขณะนี้กำลังแสดงข้อมูลสำรองจากบันทึกการเข้าเรียน'));
    }
}
