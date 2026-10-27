<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\SemesterBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class SemesterBackupTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $district;

    private string $documentId;

    private string $documentImage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->district = User::query()->create(['id' => 'admin-backup', 'name' => 'ผู้ดูแล', 'username' => 'admin-backup', 'password' => 'password', 'role' => 'district']);
        Tambon::query()->create(['id' => 't1', 'name' => 'ตำบลหนึ่ง']);
        $this->actingAs($this->district);
    }

    public function test_verified_backup_archives_and_restores_exact_graph_and_assets_without_touching_another_term_or_private_drafts(): void
    {
        $this->seedGraph();
        $before = $this->graphRows();
        $draft = TeachingDraft::query()->create(['user_id' => $this->district->id, 'tambon' => 't1', 'year' => '2569', 'semester' => '2', 'status' => 'active', 'payload' => ['private' => 'never export']]);
        $backup = $this->createBackup();
        $zipPath = $this->zipPath($backup['id']);
        $archiveBytes = file_get_contents($zipPath);
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $data = $zip->getFromName('data.json');
        $this->assertStringNotContainsString('never export', $data);
        $this->assertStringNotContainsString('password', $data);
        $this->assertStringNotContainsString('teaching_drafts', $data);
        $zip->close();
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('can_archive', false)->assertJsonPath('can_restore', false);
        $this->get(route('semester-backups.download', $backup['id']))->assertOk()->assertDownload();
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('can_archive', true);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk()->assertJsonPath('backup.status', 'archived');
        foreach (array_keys($before) as $table) {
            $this->assertDatabaseCount($table, $table === 'records' ? 1 : 0);
        }
        $this->assertDatabaseHas('records', ['id' => 'record-new', 'semester' => '2']);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id]);
        Storage::disk('public')->assertExists('records/2569/1/photo.png');
        Storage::disk('local')->delete($this->documentImage);
        Storage::disk('public')->delete('records/2569/1/photo.png');
        $imported = $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('semester.zip', $archiveBytes)])->assertCreated()->json('backup');
        $this->getJson(route('semester-backups.show', $imported['id']))->assertOk()->assertJsonPath('can_restore', true);
        $this->postJson(route('semester-backups.restore', $imported['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk()->assertJsonPath('backup.status', 'restored');
        $this->assertSame($before, $this->graphRows());
        Storage::disk('local')->assertExists($this->documentImage);
        Storage::disk('public')->assertExists('records/2569/1/photo.png');
        $this->postJson(route('semester-backups.restore', $imported['id']), ['confirmation' => 'RESTORE 1/2569'])->assertUnprocessable();
        $this->assertSame($before, $this->graphRows());
    }

    public function test_archive_requires_download_typed_confirmation_and_unchanged_data(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 2/2569', 'external_copy' => true])->assertUnprocessable();
        DB::table('records')->where('id', 'record-old')->update(['result' => 'newer edit']);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->assertDatabaseHas('records', ['id' => 'record-old', 'result' => 'newer edit']);
    }

    public function test_private_active_drafts_and_queued_exports_block_archive_without_exposing_payload(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $draft = TeachingDraft::query()->create(['user_id' => 'private-owner', 'tambon' => 't1', 'year' => '2570', 'semester' => '2', 'source_record_id' => 'record-old', 'status' => 'active', 'payload' => ['secret' => 'private-owner-only']]);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable()->assertDontSee('private-owner-only');
        $draft->update(['status' => 'finalized']);
        DB::table('export_jobs')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->district->id, 'status' => 'queued', 'filters' => '{}', 'manifest' => json_encode([['record_id' => 'record-old']]), 'total' => 1, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable();
        DB::table('export_jobs')->update(['status' => 'processing', 'expires_at' => now()->subMinute()]);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable();
        $this->assertDatabaseHas('records', ['id' => 'record-old']);
    }

    public function test_changed_image_bytes_block_archive_even_when_database_rows_are_unchanged(): void
    {
        $this->seedGraph();
        $before = $this->graphRows();
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $bytes = Storage::disk('public')->get('records/2569/1/photo.png');
        Storage::disk('public')->put('records/2569/1/photo.png', $bytes."\nchanged");
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('can_archive', false);
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertUnprocessable();
        $this->assertSame($before, $this->graphRows());
        $this->assertDatabaseHas('semester_backups', ['id' => $backup['id'], 'status' => 'ready']);
    }

    public function test_cross_term_weekly_submission_graphs_cannot_be_partially_archived(): void
    {
        $this->seedGraph();
        $submission = (string) Str::uuid();
        DB::table('weekly_submissions')->insert(['id' => $submission, 'tambon' => 't1', 'year' => '2569', 'semester' => '2', 'week' => 1, 'submitted_by' => $this->district->id]);
        DB::table('records')->where('id', 'record-old')->update(['weekly_submission_id' => $submission]);
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertUnprocessable();
        DB::table('weekly_submissions')->where('id', $submission)->update(['semester' => '1']);
        DB::table('records')->where('id', 'record-new')->update(['weekly_submission_id' => $submission]);
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertDatabaseCount('records', 2);
        $this->assertSame([], Storage::disk('local')->files('semester-backups'));
    }

    public function test_legacy_duplicate_zero_child_ids_round_trip_without_deleting_another_terms_rows(): void
    {
        $this->seedGraph();
        Schema::drop('record_images');
        Schema::create('record_images', function (Blueprint $table): void {
            $table->integer('id')->default(0);
            $table->string('record_id', 20)->index();
            $table->string('file_path');
            $table->timestamp('created_at')->nullable();
        });
        Schema::drop('record_attendance');
        Schema::create('record_attendance', function (Blueprint $table): void {
            $table->integer('id')->default(0);
            $table->string('record_id', 20)->index();
            $table->string('student_id', 50);
            $table->string('full_name');
            $table->string('group_code', 50)->nullable();
            $table->string('class_name')->nullable();
            $table->boolean('present')->default(false);
            $table->string('learning_method', 30)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['record_id', 'student_id']);
        });
        foreach (['record-old', 'record-new'] as $recordId) {
            foreach (['first', 'second'] as $student) {
                DB::table('record_images')->insert(['id' => 0, 'record_id' => $recordId, 'file_path' => 'storage/records/2569/1/photo.png']);
                DB::table('record_attendance')->insert(['id' => 0, 'record_id' => $recordId, 'student_id' => $student, 'full_name' => $student, 'present' => 1]);
            }
        }
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        foreach (['record_images', 'record_attendance'] as $table) {
            $this->assertSame(0, DB::table($table)->where('record_id', 'record-old')->count());
            $this->assertSame(2, DB::table($table)->where('record_id', 'record-new')->where('id', 0)->count());
        }
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk();
        foreach (['record_images', 'record_attendance'] as $table) {
            $this->assertSame(2, DB::table($table)->where('record_id', 'record-old')->where('id', 0)->count());
            $this->assertSame(2, DB::table($table)->where('record_id', 'record-new')->where('id', 0)->count());
        }
    }

    public function test_missing_images_prevent_an_incomplete_backup(): void
    {
        $this->seedGraph();
        Storage::disk('public')->delete('records/2569/1/photo.png');
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertSame([], Storage::disk('local')->files('semester-backups'));
    }

    public function test_tampered_data_traversal_members_and_foreign_app_keys_are_rejected(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $original = file_get_contents($this->zipPath($backup['id']));
        foreach (['data', 'traversal'] as $mutation) {
            $temporary = tempnam(sys_get_temp_dir(), 'sena-test-backup-');
            file_put_contents($temporary, $original);
            $zip = new ZipArchive;
            $zip->open($temporary);
            $zip->addFromString($mutation === 'data' ? 'data.json' : '../escape.txt', '{}');
            $zip->close();
            try {
                $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('bad.zip', file_get_contents($temporary))])->assertUnprocessable();
            } finally {
                unlink($temporary);
            }
        }
        config(['app.key' => 'another-instance']);
        $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('foreign.zip', $original)])->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 1);
    }

    public function test_scalar_or_array_manifests_are_rejected_as_validation_errors(): void
    {
        foreach (['null', '42', '"manifest"', '[]'] as $manifest) {
            $temporary = tempnam(sys_get_temp_dir(), 'sena-test-backup-');
            $zip = new ZipArchive;
            $zip->open($temporary, ZipArchive::OVERWRITE);
            $zip->addFromString('manifest.json', $manifest);
            $zip->addFromString('data.json', '{}');
            $zip->close();
            try {
                $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('invalid.zip', file_get_contents($temporary))])->assertUnprocessable()->assertJsonValidationErrors('archive');
            } finally {
                unlink($temporary);
            }
        }
        $this->assertDatabaseCount('semester_backups', 0);
    }

    public function test_restore_preflights_the_document_owner_source_unique_key(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        $existing = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $existing, 'user_id' => $this->district->id, 'source_record_id' => 'record-old', 'title' => 'private existing document', 'content_json' => '{}', 'page_settings_json' => '{}', 'objects_json' => '[]']);
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('can_restore', false);
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertUnprocessable();
        $this->assertDatabaseHas('documents', ['id' => $existing, 'title' => 'private existing document']);
        $this->assertDatabaseMissing('records', ['id' => 'record-old']);
    }

    public function test_restore_file_conflict_rolls_back_database_and_removes_only_newly_written_files(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        Storage::disk('local')->delete($this->documentImage);
        Storage::disk('public')->put('records/2569/1/photo.png', 'changed bytes');
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertUnprocessable();
        $this->assertDatabaseMissing('records', ['id' => 'record-old']);
        Storage::disk('local')->assertMissing($this->documentImage);
        $this->assertSame('changed bytes', Storage::disk('public')->get('records/2569/1/photo.png'));
    }

    public function test_every_endpoint_is_district_only_and_backup_does_not_restore_accounts_or_settings(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $teacher = User::query()->create(['id' => 'teacher', 'name' => 'ครู', 'username' => 'teacher', 'password' => 'password', 'role' => 'tambon', 'tambon' => 't1']);
        $this->actingAs($teacher);
        foreach (['index', 'preflight', 'show', 'download'] as $action) {
            $this->getJson(route('semester-backups.'.$action, in_array($action, ['show', 'download']) ? $backup['id'] : []))->assertForbidden();
        }
        foreach (['store', 'import', 'archive', 'restore', 'release', 'offload', 'terms.preview', 'terms.repair'] as $action) {
            $this->postJson(route('semester-backups.'.$action, in_array($action, ['archive', 'restore', 'release', 'offload']) ? $backup['id'] : []))->assertForbidden();
        }
    }

    public function test_index_reports_the_actual_php_upload_limit_and_failed_php_uploads_explain_the_limit(): void
    {
        $this->getJson(route('semester-backups.index'))->assertOk()
            ->assertJsonPath('limits.max_upload_mb', fn ($limit): bool => (float) $limit === round(min(SemesterBackupService::MAX_BYTES, UploadedFile::getMaxFilesize()) / 1024 / 1024, 2))
            ->assertJsonPath('limits.max_archive_mb', 2048);
        $temporary = UploadedFile::fake()->create('backup.zip');
        $failed = new UploadedFile($temporary->getRealPath(), 'backup.zip', 'application/zip', UPLOAD_ERR_INI_SIZE, true);
        $this->postJson(route('semester-backups.import'), ['archive' => $failed])->assertUnprocessable()
            ->assertJsonValidationErrors('archive')->assertSee('upload_max_filesize');
        $this->assertDatabaseCount('semester_backups', 0);
    }

    public function test_catalog_pagination_keeps_older_backups_reachable_without_returning_the_entire_catalog(): void
    {
        $oldest = (string) Str::uuid();
        for ($index = 0; $index < 31; $index++) {
            DB::table('semester_backups')->insert(['id' => $index === 0 ? $oldest : (string) Str::uuid(), 'year' => '2569', 'semester' => '1', 'status' => 'ready', 'created_by' => $this->district->id,
                'storage_path' => 'semester-backups/unused.zip', 'sha256' => str_repeat('0', 64), 'fingerprint' => str_repeat('0', 64), 'counts' => '{}', 'size_bytes' => 0, 'created_at' => now()->subDay()->addSeconds($index), 'updated_at' => now()]);
        }
        $this->getJson(route('semester-backups.index'))->assertOk()->assertJsonCount(30, 'backups')
            ->assertJsonPath('backupPagination', ['page' => 1, 'last_page' => 2, 'total' => 31, 'per_page' => 30]);
        $this->getJson(route('semester-backups.index', ['backup_page' => 2]))->assertOk()->assertJsonCount(1, 'backups')
            ->assertJsonPath('backups.0.id', $oldest)->assertJsonPath('backupPagination.page', 2);
        $this->getJson(route('semester-backups.index', ['backup_page' => 0]))->assertUnprocessable();
    }

    public function test_restore_serializes_file_writes_across_different_backup_catalog_entries(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        $lock = fopen(Storage::disk('local')->path('semester-backups/.restore.lock'), 'c+b');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertUnprocessable()->assertJsonValidationErrors('archive');
            $this->assertDatabaseMissing('records', ['id' => 'record-old']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk();
        $this->assertDatabaseHas('records', ['id' => 'record-old']);
        Storage::disk('local')->assertExists($this->documentImage);
    }

    public function test_missing_originals_require_explicit_consents_and_round_trip_the_loss_report_without_removing_text_or_references(): void
    {
        $this->seedGraph();
        Storage::disk('public')->delete('records/2569/1/photo.png');
        $preflight = $this->getJson(route('semester-backups.preflight', ['year' => '2569', 'semester' => '1']))->assertOk()
            ->assertJsonPath('available_files', 1)->assertJsonPath('missing_files.0.path', 'records/2569/1/photo.png')->json();
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1', 'allow_missing' => true])->assertUnprocessable();
        $backup = $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1', 'allow_missing' => true, 'preflight_fingerprint' => $preflight['fingerprint']])
            ->assertCreated()->assertJsonPath('backup.counts.missing_files', 1)->json('backup');
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('accept_missing_files');
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true, 'accept_missing_files' => true])->assertOk();
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('missing_files.0.reason', 'missing');
        $this->postJson(route('semester-backups.restore', $backup['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk();
        $this->assertDatabaseHas('records', ['id' => 'record-old', 'subject' => 'ภาษาไทย']);
        $this->assertDatabaseHas('record_images', ['record_id' => 'record-old', 'file_path' => 'storage/records/2569/1/photo.png']);
        Storage::disk('public')->assertMissing('records/2569/1/photo.png');
    }

    public function test_archive_release_offload_and_external_reimport_restore_exact_text_images_and_document_graph(): void
    {
        $this->seedGraph();
        $before = $this->graphRows();
        $backup = $this->createBackup();
        $external = file_get_contents($this->zipPath($backup['id']));
        $this->postJson(route('semester-backups.release', $backup['id']), ['confirmation' => 'RELEASE 1/2569', 'external_copy' => true])->assertUnprocessable();
        $this->postJson(route('semester-backups.offload', $backup['id']), ['confirmation' => 'OFFLOAD 1/2569', 'external_copy' => true])->assertUnprocessable();
        $this->get(route('semester-backups.download', $backup['id']))->assertOk();
        $this->postJson(route('semester-backups.archive', $backup['id']), ['confirmation' => 'ARCHIVE 1/2569', 'external_copy' => true])->assertOk();
        $this->getJson(route('semester-backups.show', $backup['id']))->assertOk()->assertJsonPath('storage.releasable_count', 2);
        $this->postJson(route('semester-backups.release', $backup['id']), ['confirmation' => 'RELEASE 1/2569'])->assertUnprocessable();
        $this->postJson(route('semester-backups.release', $backup['id']), ['confirmation' => 'RELEASE 1/2569', 'external_copy' => true])->assertOk()->assertJsonPath('storage.deleted_count', 2);
        Storage::disk('public')->assertMissing('records/2569/1/photo.png');
        Storage::disk('local')->assertMissing($this->documentImage);
        $this->postJson(route('semester-backups.release', $backup['id']), ['confirmation' => 'RELEASE 1/2569', 'external_copy' => true])->assertOk()->assertJsonPath('storage.deleted_count', 0);
        $this->postJson(route('semester-backups.offload', $backup['id']), ['confirmation' => 'OFFLOAD 1/2569', 'external_copy' => true])->assertOk()->assertJsonPath('backup.status', 'offline');
        $this->assertFileDoesNotExist($this->zipPath($backup['id']));
        $this->assertDatabaseHas('records', ['id' => 'record-new', 'semester' => '2']);
        $imported = $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('semester.zip', $external)])->assertCreated()->json('backup');
        $this->postJson(route('semester-backups.restore', $imported['id']), ['confirmation' => 'RESTORE 1/2569'])->assertOk();
        $this->assertSame($before, $this->graphRows());
        Storage::disk('public')->assertExists('records/2569/1/photo.png');
        Storage::disk('local')->assertExists($this->documentImage);
    }

    public function test_changed_assets_after_preflight_cannot_use_an_old_missing_file_consent(): void
    {
        $this->seedGraph();
        $preflight = $this->getJson(route('semester-backups.preflight', ['year' => '2569', 'semester' => '1']))->assertOk()->json();
        Storage::disk('public')->delete('records/2569/1/photo.png');
        $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1', 'allow_missing' => true, 'preflight_fingerprint' => $preflight['fingerprint']])->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertDatabaseCount('records', 2);
    }

    public function test_legacy_typo_period_can_be_backed_up_and_repaired_only_after_review(): void
    {
        TeachingRecord::query()->create(['id' => 'legacy-typo', 'tambon' => 't1', 'week' => 1, 'date' => '2026-05-17', 'year' => '1/2569', 'semester' => '1', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'total' => 1, 'present' => 1, 'created_by' => $this->district->id]);
        $input = ['source_year' => '1/2569', 'source_semester' => '1', 'target_year' => '2569', 'target_semester' => '1'];
        $review = $this->postJson(route('semester-backups.terms.preview'), $input)->assertOk()->assertJsonPath('can_repair', true)->json();
        $this->assertDatabaseHas('records', ['id' => 'legacy-typo', 'year' => '1/2569']);
        $this->postJson(route('semester-backups.terms.repair'), [...$input, 'fingerprint' => $review['fingerprint'], 'backup_fingerprint' => $review['preflight']['fingerprint'], 'confirmation' => 'wrong'])->assertUnprocessable();
        $result = $this->postJson(route('semester-backups.terms.repair'), [...$input, 'fingerprint' => $review['fingerprint'], 'backup_fingerprint' => $review['preflight']['fingerprint'], 'confirmation' => $review['expected_confirmation']])->assertOk()->assertJsonPath('repaired', 1)->json();
        $this->assertDatabaseHas('records', ['id' => 'legacy-typo', 'year' => '2569', 'semester' => '1', 'subject' => 'ภาษาไทย', 'present' => 1]);
        $this->assertDatabaseHas('semester_backups', ['id' => $result['backup']['id'], 'year' => '1/2569']);
        $this->get(route('semester-backups.download', $result['backup']['id']))->assertOk()->assertDownload();
    }

    public function test_malformed_legacy_semester_is_preserved_in_the_original_backup_before_repair(): void
    {
        TeachingRecord::query()->create(['id' => 'legacy-semester-typo', 'tambon' => 't1', 'week' => 2, 'date' => '2026-05-24', 'year' => '2569', 'semester' => '1/1', 'level' => 'primary', 'subject' => 'Thai', 'total' => 1, 'present' => 0, 'created_by' => $this->district->id]);
        $input = ['source_year' => '2569', 'source_semester' => '1/1', 'target_year' => '2569', 'target_semester' => '1'];
        $review = $this->postJson(route('semester-backups.terms.preview'), $input)->assertOk()->assertJsonPath('can_repair', true)->json();
        $result = $this->postJson(route('semester-backups.terms.repair'), [...$input, 'fingerprint' => $review['fingerprint'], 'backup_fingerprint' => $review['preflight']['fingerprint'], 'confirmation' => $review['expected_confirmation']])
            ->assertOk()->assertJsonPath('repaired', 1)->json();
        $this->assertDatabaseHas('records', ['id' => 'legacy-semester-typo', 'year' => '2569', 'semester' => '1', 'present' => 0]);
        $this->assertDatabaseHas('semester_backups', ['id' => $result['backup']['id'], 'year' => '2569', 'semester' => '1/1']);
        $zip = new ZipArchive;
        $zip->open($this->zipPath($result['backup']['id']));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();
        $this->assertTrue($manifest['legacy_scope']);
        $this->assertSame('1/1', $manifest['scope']['semester']);
    }

    public function test_original_version_one_backups_remain_importable_after_the_missing_report_upgrade(): void
    {
        $this->seedGraph();
        $backup = $this->createBackup();
        $temporary = tempnam(sys_get_temp_dir(), 'sena-v1-backup-');
        copy($this->zipPath($backup['id']), $temporary);
        try {
            $zip = new ZipArchive;
            $zip->open($temporary);
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifest['version'] = 1;
            unset($manifest['missing_files'], $manifest['legacy_scope'], $manifest['signature']);
            $manifest['signature'] = hash_hmac('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), config('app.key'));
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->close();
            $this->postJson(route('semester-backups.import'), ['archive' => UploadedFile::fake()->createWithContent('original-v1.zip', file_get_contents($temporary))])
                ->assertCreated()->assertJsonPath('backup.counts.records', 1)->assertJsonPath('backup.counts.missing_files', 0);
        } finally {
            unlink($temporary);
        }
    }

    private function createBackup(): array
    {
        return $this->postJson(route('semester-backups.store'), ['year' => '2569', 'semester' => '1'])->assertCreated()->assertJsonPath('backup.counts.records', 1)->json('backup');
    }

    private function zipPath(string $id): string
    {
        return Storage::disk('local')->path(DB::table('semester_backups')->where('id', $id)->value('storage_path'));
    }

    private function seedGraph(): void
    {
        TeachingRecord::query()->create(['id' => 'record-old', 'tambon' => 't1', 'week' => 1, 'date' => '2026-05-17', 'year' => '2569', 'semester' => '1', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'total' => 1, 'present' => 1, 'created_by' => $this->district->id]);
        TeachingRecord::query()->create(['id' => 'record-new', 'tambon' => 't1', 'week' => 1, 'date' => '2026-11-01', 'year' => '2569', 'semester' => '2', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'created_by' => $this->district->id]);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('records/2569/1/photo.png', $bytes);
        DB::table('record_images')->insert(['id' => 42, 'record_id' => 'record-old', 'file_path' => 'storage/records/2569/1/photo.png']);
        DB::table('record_attendance')->insert(['id' => 51, 'record_id' => 'record-old', 'student_id' => 'student1', 'full_name' => 'นักศึกษา', 'present' => 1]);
        DB::table('record_document_layouts')->insert(['record_id' => 'record-old', 'settings' => '{"pdf_font_size_pt":"16"}', 'content' => '{"result":"edited"}']);
        $this->documentId = (string) Str::uuid();
        $this->documentImage = 'documents/'.$this->documentId.'/asset.png';
        Storage::disk('local')->put($this->documentImage, $bytes);
        DB::table('documents')->insert(['id' => $this->documentId, 'user_id' => $this->district->id, 'source_record_id' => 'record-old', 'title' => 'เอกสารที่จัดไว้', 'content_json' => '{"anchors":["stable-paragraph"]}', 'page_settings_json' => '{}', 'objects_json' => '[]', 'binding_key' => 'record:record-old', 'binding_snapshot' => '{"kind":"record","record_id":"record-old"}']);
        DB::table('document_assets')->insert(['id' => (string) Str::uuid(), 'document_id' => $this->documentId, 'name' => 'asset.png', 'storage_path' => $this->documentImage, 'mime' => 'image/png', 'size' => strlen($bytes), 'width' => 1, 'height' => 1]);
        DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'document_id' => $this->documentId, 'user_id' => $this->district->id, 'revision' => 1, 'snapshot_json' => '{"stable":"kept"}']);
        $snapshot = json_encode(['record' => ['id' => 'record-old'], 'images' => [['id' => 42, 'file_path' => 'storage/records/2569/1/photo.png']], 'advanced_document' => ['id' => $this->documentId]]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'record_id' => 'record-old', 'revision' => 1, 'snapshot' => $snapshot, 'content_hash' => hash('sha256', $snapshot), 'created_by' => $this->district->id, 'created_at' => now()]);
    }

    private function graphRows(): array
    {
        $tables = ['records', 'record_images', 'record_attendance', 'record_document_layouts', 'documents', 'document_assets', 'document_versions', 'record_revisions'];
        $rows = [];
        foreach ($tables as $table) {
            $key = $table === 'record_document_layouts' ? 'record_id' : 'id';
            $query = DB::table($table)->orderBy($key);
            if ($table === 'records') {
                $query->where('id', 'record-old');
            }
            $rows[$table] = $query->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }
}
