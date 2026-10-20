<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_login_dashboard_records_and_student_api_are_compatible(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        User::create([
            'id' => 'district-1',
            'name' => 'ผู้ดูแลอำเภอ',
            'username' => 'admin',
            'password' => 'secret123',
            'role' => 'district',
        ]);
        $record = TeachingRecord::create([
            'id' => 'legacy-record-1',
            'tambon' => 't1',
            'week' => 4,
            'date' => '2026-08-30',
            'level' => 'ประถมศึกษา',
            'semester' => '1',
            'year' => '2569',
            'subject' => 'ภาษาไทย',
            'total' => 1,
            'present' => 1,
            'created_by' => 'district-1',
        ]);
        $record->attendance()->create([
            'student_id' => 'S001',
            'full_name' => 'นักศึกษา ทดสอบ',
            'present' => true,
        ]);

        $this->postJson('/api/auth.php?action=login', [
            'username' => 'admin',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.role', 'district')
            ->assertJsonMissingPath('data.user.password');

        $this->getJson('/api/dashboard.php')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.stats.total', 1);

        $this->getJson('/api/records.php')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.records.0.date', '2026-08-30');

        Http::fake(['*' => Http::response(['success' => true, 'data' => [[
            'student_id' => 'S001',
            'full_name' => 'นักศึกษา ทดสอบ',
            'password' => 'must-not-leak',
        ]]])]);

        $this->getJson('/api/student_api.php')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.items.0.student_id', 'S001')
            ->assertJsonMissingPath('data.items.0.password');
    }

    public function test_legacy_pdf_download_returns_a_real_pdf(): void
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create([
            'id' => 'district-pdf',
            'name' => 'ผู้ดูแลอำเภอ',
            'username' => 'district-pdf',
            'password' => 'not-used',
            'role' => 'district',
        ]);
        $record = TeachingRecord::create([
            'id' => 'legacy-pdf-1',
            'tambon' => 't1',
            'week' => 4,
            'date' => '2026-08-30',
            'level' => 'ประถมศึกษา',
            'semester' => '1',
            'year' => '2569',
            'subject' => 'ภาษาไทย',
            'total' => 1,
            'present' => 1,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get('/api/download_pdf.php?id='.$record->id);

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }
}
