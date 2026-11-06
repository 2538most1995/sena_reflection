<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\StudentApiConnection;
use App\Models\Tambon;
use App\Models\User;
use App\Services\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttendanceReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_retains_legacy_loose_identity_counts_for_numeric_string_ids(): void
    {
        $rows = [];
        foreach (['001', '1'] as $id) {
            $rows[] = (object) ['record_id' => $id, 'student_id' => $id, 'full_name' => 'Fixture', 'tambon' => 'own', 'tambon_name' => 'Fixture', 'date' => '2026-10-01', 'week' => 1, 'level' => 'ประถมศึกษา', 'subject' => 'Fixture', 'subject_code' => '', 'group_code' => 'G', 'class_name' => 'Fixture', 'present' => true, 'learning_method' => 'พบกลุ่ม'];
        }
        $method = new \ReflectionMethod(AttendanceReportService::class, 'aggregate');
        $report = $method->invoke(new AttendanceReportService, $rows, '');
        $this->assertSame(1, $report['summary']['students']);
        $this->assertSame(1, $report['summary']['sessions']);
        $this->assertCount(2, $report['students']);
    }

    public function test_large_attendance_report_retains_scope_totals_timeline_and_ordering_with_bounded_queries(): void
    {
        Http::preventStrayRequests();
        Cache::flush();
        Tambon::insert([['id' => 'own', 'name' => 'Fixture own'], ['id' => 'other', 'name' => 'Fixture other']]);
        $teacher = User::create(['id' => 'report-teacher', 'username' => 'report-teacher', 'name' => 'Fixture teacher', 'password' => 'fixture-only', 'role' => 'tambon', 'tambon' => 'own']);
        $district = User::create(['id' => 'report-district', 'username' => 'report-district', 'name' => 'Fixture district', 'password' => 'fixture-only', 'role' => 'district']);
        StudentApiConnection::create(['id' => 1, 'token' => Crypt::encryptString('fixture-report-token'), 'latest_term' => '1/2569']);
        $records = [];
        $attendance = [];
        foreach (['own' => 120, 'other' => 60] as $tambon => $sessions) {
            for ($session = 1; $session <= $sessions; $session++) {
                $id = $tambon.'-'.str_pad((string) $session, 4, '0', STR_PAD_LEFT);
                $records[] = ['id' => $id, 'tambon' => $tambon, 'week' => ($session - 1) % 20 + 1, 'date' => '2026-10-'.str_pad((string) (($session - 1) % 20 + 1), 2, '0', STR_PAD_LEFT), 'level' => 'ประถมศึกษา', 'year' => '2569', 'semester' => '1', 'subject' => 'Fixture subject', 'class_group_code' => 'G-'.$tambon, 'class_name' => 'Fixture '.$tambon, 'total' => 100, 'present' => 50, 'created_by' => $teacher->id];
                for ($student = 1; $student <= 100; $student++) {
                    $attendance[] = ['record_id' => $id, 'student_id' => 'S-'.$tambon.'-'.str_pad((string) $student, 3, '0', STR_PAD_LEFT), 'full_name' => 'Fixture '.str_pad((string) $student, 3, '0', STR_PAD_LEFT), 'group_code' => 'G-'.$tambon, 'class_name' => 'Fixture '.$tambon, 'present' => $student <= 50, 'learning_method' => $student <= 50 ? ($student % 2 === 0 ? 'ออนไลน์' : 'พบกลุ่ม') : null];
                }
            }
        }
        DB::table('records')->insert($records);
        foreach (array_chunk($attendance, 500) as $chunk) {
            DB::table('record_attendance')->insert($chunk);
        }
        unset($attendance, $records, $chunk);
        $this->actingAs($teacher);
        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memory = memory_get_usage();
            $started = hrtime(true);
            $response = $this->getJson(route('attendance.analysis', ['year' => '2569', 'semester' => '1', 'student_id' => 'S-own-001']), ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())]);
            $samples[] = ['queries' => count(DB::getQueryLog()), 'db_ms' => round(array_sum(array_column(DB::getQueryLog(), 'time')), 3), 'response_ms' => round((hrtime(true) - $started) / 1e6, 3), 'peak_mb' => round((memory_get_peak_usage() - $memory) / 1048576, 3), 'json_bytes' => strlen($response->getContent())];
            DB::disableQueryLog();
            $response->assertOk();
            $report = $response->json('props.report');
            $samples[$sample]['report_sha256'] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
            $this->assertSame(['sessions' => 120, 'students' => 100, 'present' => 6000, 'absent' => 6000, 'onsite' => 3000, 'online' => 3000, 'rate' => 50], $report['summary']);
            $this->assertCount(1, $report['classes']);
            $this->assertSame('S-own-051', $report['students'][0]['student_id']);
            $this->assertSame(120, $report['students'][0]['absent']);
            $this->assertCount(120, $report['selected_timeline']);
            $this->assertSame('2026-10-01 00:00:00', $report['selected_timeline'][0]['date']);
            $this->assertSame(20, $report['selected_timeline'][119]['week']);
            $this->assertLessThanOrEqual(20, $samples[$sample]['queries']);
            unset($response, $report);
            gc_collect_cycles();
        }
        if (getenv('SENA_PERF_REPORT')) {
            fwrite(STDOUT, json_encode(['attendance_endpoint' => ['engine' => DB::getDriverName(), 'records' => 180, 'attendance_rows' => 18000, 'visible_rows' => 12000, 'samples' => 5, 'measurements' => $samples]], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        $service = app(AttendanceReportService::class);
        $this->assertSame(120, $service->build($teacher, ['year' => '2569', 'semester' => '1', 'tambon' => 'other'])['summary']['sessions']);
        $this->assertSame(60, $service->build($district, ['year' => '2569', 'semester' => '1', 'tambon' => 'other'])['summary']['sessions']);
        $this->assertSame(0, $service->build($teacher, ['year' => '2568'])['summary']['sessions']);
        $filtered = $service->build($teacher, ['class_group' => 'G-own', 'search' => 'S-own-001']);
        $this->assertSame(1, $filtered['summary']['students']);
        $this->assertSame(120, $filtered['summary']['sessions']);
        Http::assertNothingSent();
    }
}
