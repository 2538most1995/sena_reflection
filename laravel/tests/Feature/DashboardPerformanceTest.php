<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_filtered_dashboard_keeps_exact_totals_and_bounded_queries(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        Tambon::create(['id' => 'performance-own', 'name' => 'Performance Own']);
        Tambon::create(['id' => 'performance-other', 'name' => 'Performance Other']);
        $teacher = User::create(['id' => 'performance-user', 'username' => 'performance-user', 'name' => 'Performance User', 'password' => 'fixture-only', 'role' => 'tambon', 'tambon' => 'performance-own']);
        $district = User::create(['id' => 'performance-admin', 'username' => 'performance-admin', 'name' => 'Performance Admin', 'password' => 'fixture-only', 'role' => 'district']);
        $records = [];
        $attendance = [];
        $images = [];
        foreach (range(1, 3600) as $index) {
            $id = 'perf-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
            $records[] = ['id' => $id, 'tambon' => $index <= 2400 ? 'performance-own' : 'performance-other', 'year' => $index <= 3000 ? '2569' : '2568', 'semester' => '2', 'week' => $index % 60 + 1, 'date' => '2026-11-06', 'level' => 'ประถมศึกษา', 'subject' => 'Performance Fixture', 'subject_code' => 'PERF', 'total' => 20, 'present' => 5, 'created_at' => '2026-11-06 00:00:00', 'updated_at' => '2026-11-06 00:00:00'];
            foreach (range(1, 5) as $student) {
                $attendance[] = ['record_id' => $id, 'student_id' => 'fixture-'.$student, 'full_name' => 'Fixture', 'present' => true];
            }
            $images[] = ['record_id' => $id, 'file_path' => 'fixture-only.png'];
        }
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('records')->insert($chunk);
        }
        foreach (array_chunk($attendance, 300) as $chunk) {
            DB::table('record_attendance')->insert($chunk);
        }
        foreach (array_chunk($images, 300) as $chunk) {
            DB::table('record_images')->insert($chunk);
        }
        unset($records, $attendance, $images);
        foreach ([$teacher, $district] as $owner) {
            foreach (range(1, 6) as $week) {
                $levels = [];
                foreach (['1', '2', '3'] as $level) {
                    $roster = [];
                    foreach (range(1, 100) as $student) {
                        $roster[] = ['student_id' => 'fixture-'.$student, 'full_name' => 'Synthetic Fixture Student '.$student, 'group_code' => 'fixture-group', 'present' => false, 'learning_method' => null];
                    }
                    $levels[$level] = ['subject' => 'Fixture', 'class_group_code' => 'fixture-group', 'class_name' => 'Fixture Group', 'result' => str_repeat('Synthetic fixture text. ', 400), 'problem' => str_repeat('Synthetic fixture text. ', 400), 'suggestion' => str_repeat('Synthetic fixture text. ', 400), 'attendance_mode' => 'roster', 'attendance' => $roster, 'total' => 100, 'present' => 0];
                }
                TeachingDraft::create(['user_id' => $owner->id, 'tambon' => 'performance-own', 'year' => '2569', 'semester' => '2', 'week' => $week, 'last_saved_at' => now(), 'payload' => ['scope' => ['tambon' => 'performance-own', 'year' => '2569', 'semester' => '2', 'week' => $week, 'date' => '2026-11-06'], 'enabled_level_codes' => ['1', '2', '3'], 'active_level_code' => '1', 'levels' => $levels]]);
            }
        }

        $measurements = [];
        foreach ([['teacher', $teacher, 2400], ['district', $district, 3000]] as [$role, $user, $count]) {
            $this->actingAs($user);
            $this->get(route('dashboard', ['year' => '2569', 'semester' => '2']))->assertOk();
            $samples = [];
            for ($run = 0; $run < 7; $run++) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $memory = memory_get_usage(true);
                $started = hrtime(true);
                $response = $this->get(route('dashboard', ['year' => '2569', 'semester' => '2']));
                $elapsed = (hrtime(true) - $started) / 1000000;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $samples[] = ['response_ms' => round($elapsed, 3), 'query_count' => count($queries), 'query_ms' => round(array_sum(array_column($queries, 'time')), 3), 'memory_delta_mb' => round((memory_get_usage(true) - $memory) / 1048576, 3), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 3), 'response_bytes' => strlen($response->getContent())];
                $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('records.data', 30)->where('records.total', $count)->where('records.last_page', (int) ceil($count / 30))->where('summary.record_count', $count)->where('summary.week_count', 60)->where('summary.total_students', $count * 20)->where('summary.total_present', $count * 5)->where('summary.unique_present_students', 5));
                $this->assertLessThanOrEqual($role === 'teacher' ? 10 : 11, count($queries));
                $this->assertLessThan(50000, strlen($response->getContent()));
            }
            $measurements[$role] = $samples;
            $plans[$role] = [];
            foreach ($queries as $query) {
                if (! str_contains($query['query'], 'visible_periods') && ! str_contains($query['query'], 'images_count')) {
                    continue;
                }
                $plans[$role][] = ['query' => $query['query'], 'plan' => DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings'])];
            }
        }
        if ($path = getenv('SENA_DASHBOARD_BENCHMARK_OUTPUT')) {
            file_put_contents($path, json_encode(['fixture_records' => 3600, 'fixture_attendance' => 18000, 'database' => 'SQLite in-memory', 'measurements' => $measurements, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseCount('records', 3600);
        $this->assertDatabaseCount('record_attendance', 18000);
    }
}
