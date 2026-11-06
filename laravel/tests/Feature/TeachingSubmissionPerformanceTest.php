<?php

namespace Tests\Feature;

use App\Models\RecordAttendance;
use App\Models\RecordDocumentLayout;
use App\Models\RecordImage;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use App\Services\DraftService;
use App\Services\EducationLevelService;
use App\Services\FinalizeTeachingSubmission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeachingSubmissionPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_finalizing_large_rosters_preserves_settings_and_attendance_without_building_unused_previews(): void
    {
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        Tambon::query()->create(['id' => 'perf-tambon', 'name' => 'ตำบลทดสอบประสิทธิภาพ']);
        $user = User::query()->create([
            'id' => 'perf-owner', 'username' => 'perf-owner', 'name' => 'ครูทดสอบ',
            'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 'perf-tambon',
        ]);
        Setting::query()->insert(array_map(fn (int $index): array => ['setting_key' => 'fixture_setting_'.$index, 'setting_value' => str_repeat('x', 100)], range(1, 250)));
        $this->actingAs($user);
        $metrics = [];
        $queries = null;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($queries !== null) {
                $queries[] = ['sql' => $query->sql, 'milliseconds' => $query->time];
            }
        });

        foreach ([10, 11, 12] as $week) {
            $levels = [];
            foreach (['1', '2', '3'] as $level) {
                $levels[$level] = [
                    'subject' => 'วิชาทดสอบ '.$level, 'subject_code' => 'PERF'.$level,
                    'class_group_code' => 'PERF-'.$level, 'class_name' => 'กลุ่มทดสอบ '.$level,
                    'result' => 'ผลการเรียนรู้สำหรับทดสอบประสิทธิภาพ', 'problem' => '', 'suggestion' => '',
                    'attendance_mode' => 'roster', 'attendance_reviewed' => false,
                    'attendance' => array_map(fn (int $index): array => [
                        'student_id' => $level.'-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                        'full_name' => 'ผู้เรียนทดสอบ '.$index, 'group_code' => 'PERF-'.$level,
                        'class_name' => 'กลุ่มทดสอบ '.$level, 'present' => $index <= 150,
                        'learning_method' => $index <= 150 ? 'พบกลุ่ม' : null,
                    ], range(1, 200)),
                    'document' => ['settings' => ['pdf_margin_left_px' => '105', 'sig1_name' => 'ครูเจ้าของร่าง']],
                    'image_ids' => [],
                ];
            }
            $draft = app(DraftService::class)->create($user, ['payload' => [
                'scope' => ['tambon' => 'perf-tambon', 'week' => $week, 'date' => '2026-11-06', 'semester' => '2', 'year' => '2569'],
                'levels' => $levels, 'enabled_level_codes' => ['1', '2', '3'], 'active_level_code' => '1',
            ]]);
            $queries = [];
            $started = hrtime(true);
            $memory = memory_get_usage(true);

            $result = app(FinalizeTeachingSubmission::class)->finalize($user, $draft, 1, (string) Str::uuid(), null, true);

            $milliseconds = (hrtime(true) - $started) / 1_000_000;
            $measuredQueries = $queries;
            $queries = null;
            $metrics[] = [
                'levels' => 3, 'students_per_level' => 200, 'settings' => 250,
                'query_count' => count($measuredQueries),
                'query_ms' => round(array_sum(array_column($measuredQueries, 'milliseconds')), 3),
                'service_ms' => round($milliseconds, 3),
                'allocated_delta_mb' => round((memory_get_usage(true) - $memory) / 1048576, 3),
                'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 3),
                'draft_selects' => count(array_filter($measuredQueries, fn (array $query): bool => str_starts_with($query['sql'], 'select * from "teaching_drafts"'))),
            ];
            $this->assertLessThanOrEqual(100, count($measuredQueries), 'Finalization must not rebuild a complete unused preview for each level.');
            $this->assertLessThanOrEqual(5, $metrics[array_key_last($metrics)]['draft_selects']);
            $this->assertCount(3, $result['record_ids']);
            $this->assertSame(600, RecordAttendance::query()->whereIn('record_id', $result['record_ids'])->count());
            $this->assertSame(450, RecordAttendance::query()->whereIn('record_id', $result['record_ids'])->where('present', true)->count());
            foreach (TeachingRecord::query()->whereIn('id', $result['record_ids'])->get() as $record) {
                $this->assertSame(200, $record->total);
                $this->assertSame(150, $record->present);
                $this->assertContains($record->level, EducationLevelService::NAMES);
                $layout = RecordDocumentLayout::query()->where('record_id', $record->id)->firstOrFail();
                $this->assertSame('105', $layout->settings['pdf_margin_left_px']);
                $this->assertSame('ครูเจ้าของร่าง', $layout->settings['sig1_name']);
            }
            $this->assertSame('finalized', TeachingDraft::query()->findOrFail($draft->id)->status);
        }
        if ($path = getenv('SENA_PERF_EVIDENCE')) {
            file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_opening_an_existing_week_reads_each_source_collection_once_and_keeps_its_fingerprint(): void
    {
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        Tambon::query()->create(['id' => 'edit-tambon', 'name' => 'ตำบลทดสอบ']);
        $user = User::query()->create([
            'id' => 'edit-owner', 'username' => 'edit-owner', 'name' => 'ครูทดสอบ',
            'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 'edit-tambon',
        ]);
        $submission = WeeklySubmission::query()->create(['tambon' => 'edit-tambon', 'week' => 8, 'year' => '2569', 'semester' => '2', 'status' => 'complete', 'submitted_by' => $user->id]);
        $source = null;
        foreach (EducationLevelService::NAMES as $code => $name) {
            $record = TeachingRecord::query()->create([
                'id' => 'perf-source-'.$code, 'weekly_submission_id' => $submission->id,
                'tambon' => 'edit-tambon', 'week' => 8, 'year' => '2569', 'semester' => '2', 'date' => '2026-11-06',
                'level' => $name, 'subject' => 'วิชาทดสอบ', 'class_group_code' => 'PERF-'.$code,
                'total' => 200, 'present' => 150, 'result' => 'ผลการเรียนรู้', 'created_by' => $user->id,
            ]);
            $source ??= $record;
            RecordAttendance::insertAdaptively(array_map(fn (int $index): array => [
                'record_id' => $record->id, 'student_id' => $code.'-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'full_name' => 'ผู้เรียนทดสอบ '.$index, 'present' => $index <= 150, 'learning_method' => $index <= 150 ? 'พบกลุ่ม' : null,
                'created_at' => now(),
            ], range(1, 200)));
            for ($index = 1; $index <= 4; $index++) {
                $path = 'records/source-'.$code.'-'.$index.'.png';
                Storage::disk('public')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhx0AAAAASUVORK5CYII='));
                RecordImage::createWithAdaptiveKey(['record_id' => $record->id, 'file_path' => 'storage/'.$path, 'created_at' => now()]);
            }
            RecordDocumentLayout::query()->create(['record_id' => $record->id, 'settings' => ['pdf_margin_left_px' => '105'], 'content' => [], 'updated_by' => $user->id]);
        }
        $service = app(DraftService::class);
        $fingerprint = $service->sourceFingerprint($source);
        $queries = null;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($queries !== null) {
                $queries[] = ['sql' => $query->sql, 'milliseconds' => $query->time];
            }
        });
        $metrics = [];
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $queries = [];
            $started = hrtime(true);
            $memory = memory_get_usage(true);

            $draft = $service->create($user, ['source_record_id' => $source->id, 'refresh_source' => true]);

            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $measured = $queries;
            $queries = null;
            $metrics[] = ['levels' => 3, 'students_per_level' => 200, 'images' => 12, 'query_count' => count($measured),
                'query_ms' => round(array_sum(array_column($measured, 'milliseconds')), 3), 'service_ms' => round($elapsed, 3),
                'allocated_delta_mb' => round((memory_get_usage(true) - $memory) / 1048576, 3), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 3)];
            $this->assertLessThanOrEqual(21, count($measured), 'Source records and their attendance/images/layouts should be loaded once for the draft.');
            $this->assertSame($fingerprint, $draft->payload['source_fingerprint']);
            $this->assertCount(12, $draft->images);
            foreach (['1', '2', '3'] as $code) {
                $this->assertCount(200, $draft->payload['levels'][$code]['attendance']);
                $this->assertCount(4, $draft->payload['levels'][$code]['image_ids']);
                $this->assertSame('105', $draft->payload['levels'][$code]['document']['settings']['pdf_margin_left_px']);
            }
        }
        if ($path = getenv('SENA_PERF_EDIT_EVIDENCE')) {
            file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_finalization_settings_equal_full_preview_settings_and_require_the_draft_owner(): void
    {
        Http::preventStrayRequests();
        Tambon::query()->create(['id' => 'settings-tambon', 'name' => 'ตำบลทดสอบ']);
        $user = User::query()->create([
            'id' => 'settings-owner', 'username' => 'settings-owner', 'name' => 'ครูทดสอบ',
            'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 'settings-tambon',
        ]);
        $this->actingAs($user);
        $service = app(DraftService::class);
        $draft = $service->create($user, ['payload' => [
            'scope' => ['tambon' => 'settings-tambon', 'week' => 7, 'year' => '2569', 'semester' => '2', 'date' => '2026-11-06'],
            'enabled_level_codes' => ['1'], 'active_level_code' => '1',
            'levels' => ['1' => ['subject' => 'วิชาทดสอบ', 'class_name' => 'กลุ่มทดสอบ', 'result' => 'ผลการเรียนรู้',
                'attendance_mode' => 'counts', 'total' => 20, 'present' => 10,
                'document' => ['settings' => ['pdf_margin_left_px' => '105', 'sig1_name' => 'ครูเจ้าของร่าง']],
            ]],
        ]]);
        $overrides = ['settings' => ['pdf_margin_right_px' => '111']];
        $preview = $service->snapshotForPreview($user, $draft, '1', $overrides);

        $settings = $service->settingsForFinalization($user, $draft, '1', $overrides);

        $this->assertSame($preview['settings'], $settings);
        $this->assertSame('105', $settings['pdf_margin_left_px']);
        $this->assertSame('111', $settings['pdf_margin_right_px']);
        $this->assertSame('ครูเจ้าของร่าง', $settings['sig1_name']);
        $other = clone $user;
        $other->id = 'different-owner';
        $this->expectException(ModelNotFoundException::class);
        $service->settingsForFinalization($other, $draft, '1');
    }
}
