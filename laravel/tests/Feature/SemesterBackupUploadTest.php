<?php

namespace Tests\Feature;

use App\Http\Controllers\SemesterBackupUploadController;
use App\Models\Tambon;
use App\Models\User;
use App\Services\SemesterBackupService;
use App\Services\SemesterBackupUploadService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SemesterBackupUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $owner;

    private User $other;

    private SemesterBackupUploadService $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->owner = User::query()->create(['id' => 'upload-admin', 'username' => 'upload-admin', 'name' => 'ผู้ดูแล', 'role' => 'district', 'password' => 'password']);
        $this->other = User::query()->create(['id' => 'upload-other', 'username' => 'upload-other', 'name' => 'ผู้ดูแลอีกคน', 'role' => 'district', 'password' => 'password']);
        $this->uploads = app(SemesterBackupUploadService::class);
        Route::middleware('web')->prefix('testing-semester-uploads')->group(function (): void {
            Route::get('/', [SemesterBackupUploadController::class, 'index']);
            Route::post('/', [SemesterBackupUploadController::class, 'start']);
            Route::post('/{upload}/{part}', [SemesterBackupUploadController::class, 'part'])->whereNumber('part');
            Route::post('/{upload}/finish', [SemesterBackupUploadController::class, 'finish']);
            Route::delete('/{upload}', [SemesterBackupUploadController::class, 'cancel']);
        });
    }

    public function test_all_controller_operations_require_a_district_account_and_owner_binding(): void
    {
        $this->getJson('/testing-semester-uploads')->assertForbidden();
        $this->postJson('/testing-semester-uploads', ['name' => 'backup.zip', 'size' => 22])->assertForbidden();
        $tambon = User::query()->create(['id' => 'upload-teacher', 'username' => 'upload-teacher', 'name' => 'ครู', 'password' => 'password', 'role' => 'tambon']);
        $this->actingAs($tambon)->getJson('/testing-semester-uploads')->assertForbidden();
        $this->postJson('/testing-semester-uploads', ['name' => 'backup.zip', 'size' => 22])->assertForbidden();
        $session = $this->uploads->start($this->owner, 'backup.zip', 22);
        $this->actingAs($tambon)->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertForbidden();
        $this->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk(str_repeat('x', 22))])->assertForbidden();
        $this->deleteJson('/testing-semester-uploads/'.$session['id'])->assertForbidden();
        $this->actingAs($this->other)->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk(str_repeat('x', 22))])->assertNotFound();
        $this->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertNotFound();
        $this->deleteJson('/testing-semester-uploads/'.$session['id'])->assertNotFound();
        $this->assertFileExists($this->stage($session['id']));
        $this->assertDatabaseCount('records', 0);
    }

    public function test_init_rejects_oversize_names_traversal_and_too_small_files_without_creating_staging_files(): void
    {
        $this->actingAs($this->owner);
        foreach ([['name' => '../backup.zip', 'size' => 22], ['name' => 'backup.exe', 'size' => 22], ['name' => 'backup.zip', 'size' => 21], ['name' => 'backup.zip', 'size' => SemesterBackupService::MAX_BYTES + 1]] as $payload) {
            $this->postJson('/testing-semester-uploads', $payload)->assertUnprocessable();
        }
        $this->assertSame([], Storage::disk('local')->allFiles('semester-backup-uploads'));
    }

    public function test_chunk_sizes_are_bounded_and_sequential_retries_are_idempotent_with_partial_tail_recovery(): void
    {
        $size = 8 * 1024 * 1024 + 17;
        $session = $this->uploads->start($this->owner, 'backup.zip', $size);
        $this->assertGreaterThanOrEqual(64 * 1024, $session['chunk_size']);
        $this->assertLessThanOrEqual(8 * 1024 * 1024, $session['chunk_size']);
        $this->assertLessThanOrEqual(UploadedFile::getMaxFilesize() - 64 * 1024, $session['chunk_size']);
        $this->actingAs($this->owner);
        $this->post('/testing-semester-uploads/'.$session['id'].'/1', ['chunk' => $this->chunk(str_repeat('b', min($session['chunk_size'], $size - $session['chunk_size'])))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk('short')], ['Accept' => 'application/json'])->assertUnprocessable();
        $bytes = str_repeat('a', $session['chunk_size']);
        $first = $this->uploads->part($this->owner, $session['id'], 0, $this->chunk($bytes));
        $this->assertSame(1, $first['uploaded_parts']);
        file_put_contents($this->stage($session['id']), 'tail from interrupted append', FILE_APPEND);
        $retry = $this->uploads->part($this->owner, $session['id'], 0, $this->chunk($bytes));
        $this->assertSame($first, $retry);
        clearstatcache(true, $this->stage($session['id']));
        $this->assertSame($session['chunk_size'], filesize($this->stage($session['id'])));
        $this->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk(str_repeat('z', $session['chunk_size']))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertUnprocessable();
        $this->assertSame($bytes, file_get_contents($this->stage($session['id'])));
    }

    public function test_one_staging_archive_handles_multiple_chunks_and_verifies_the_whole_zip_without_restoring_source_data(): void
    {
        Tambon::query()->create(['id' => 'upload-t1', 'name' => 'ตำบลทดสอบ']);
        DB::table('records')->insert(['id' => 'upload-record', 'tambon' => 'upload-t1', 'year' => '2569', 'semester' => '1', 'week' => 1, 'date' => '2026-05-13', 'subject' => 'ภาษาไทย', 'result' => 'เก็บข้อความและรูปเดิมทุกตัวอักษร']);
        $photo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        $photo .= random_bytes(8 * 1024 * 1024 + 100);
        Storage::disk('public')->put('records/2569/1/upload.png', $photo);
        DB::table('record_images')->insert(['record_id' => 'upload-record', 'file_path' => 'storage/records/2569/1/upload.png']);
        $backup = app(SemesterBackupService::class)->create($this->owner, '2569', '1');
        $source = app(SemesterBackupService::class)->downloadPath($backup['id']);
        $size = filesize($source);
        $session = $this->uploads->start($this->owner, 'full-semester.zip', $size);
        $this->assertGreaterThan(1, $session['parts']);
        $input = fopen($source, 'rb');
        try {
            for ($part = 0; $part < $session['parts']; $part++) {
                $bytes = fread($input, $session['chunk_size']);
                $state = $this->uploads->part($this->owner, $session['id'], $part, $this->chunk($bytes));
                $this->assertSame($part + 1, $state['uploaded_parts']);
            }
        } finally {
            fclose($input);
        }
        $this->assertSame($size, $state['uploaded_bytes']);
        $files = Storage::disk('local')->files('semester-backup-uploads/'.$session['id']);
        $this->assertCount(1, array_filter($files, fn (string $path): bool => str_ends_with($path, '.zip')));
        $result = $this->uploads->finish($this->owner, $session['id']);
        $this->assertSame('imported', $result['backup']['status']);
        $expected = ['records' => 1, 'attendance' => 0, 'images' => 1, 'documents' => 0, 'revisions' => 0];
        $this->assertSame($expected, array_intersect_key($result['backup']['counts'], $expected));
        $this->assertFileDoesNotExist($this->stage($session['id']));
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseHas('records', ['id' => 'upload-record', 'result' => 'เก็บข้อความและรูปเดิมทุกตัวอักษร']);
        $this->assertDatabaseCount('record_images', 1);
        $this->assertDatabaseCount('semester_backups', 2);
        $this->assertSame($result, $this->uploads->finish($this->owner, $session['id']));
        $this->assertSame([], $this->uploads->list($this->owner));
        $this->assertDatabaseCount('semester_backups', 2);
        $target = app(SemesterBackupService::class)->downloadPath($result['backup']['id']);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $target));
        $this->uploads->cancel($this->owner, $session['id']);
        $this->assertFileExists($target);
    }

    public function test_corrupt_committed_chunks_extra_bytes_and_non_zip_content_never_create_a_backup(): void
    {
        $this->actingAs($this->owner);
        $session = $this->uploads->start($this->owner, 'invalid.zip', 22);
        $bytes = "PK\x03\x04".str_repeat('a', 18);
        $this->uploads->part($this->owner, $session['id'], 0, $this->chunk($bytes));
        file_put_contents($this->stage($session['id']), 'x'.$bytes);
        $this->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertUnprocessable();
        file_put_contents($this->stage($session['id']), 'x'.substr($bytes, 1));
        $this->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk($bytes)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_invalid_complete_zip_reaches_signature_validation_without_creating_catalog_entries(): void
    {
        $session = $this->uploads->start($this->owner, 'invalid.zip', 22);
        $this->uploads->part($this->owner, $session['id'], 0, $this->chunk("PK\x03\x04".str_repeat('x', 18)));
        $this->actingAs($this->owner)->postJson('/testing-semester-uploads/'.$session['id'].'/finish')->assertUnprocessable();
        $this->assertDatabaseCount('semester_backups', 0);
        $this->assertFileExists($this->stage($session['id']));
    }

    public function test_expiry_cleanup_and_cancel_only_remove_the_owners_private_session(): void
    {
        $mine = $this->uploads->start($this->owner, 'mine.zip', 22);
        $other = $this->uploads->start($this->other, 'other.zip', 22);
        Storage::disk('local')->put('semester-backup-uploads/unrelated.txt', 'keep');
        $this->travel(25)->hours();
        $this->actingAs($this->owner)->post('/testing-semester-uploads/'.$mine['id'].'/0', ['chunk' => $this->chunk(str_repeat('x', 22))], ['Accept' => 'application/json'])->assertGone();
        $this->assertSame(1, $this->uploads->pruneExpired($this->owner));
        $this->assertFileDoesNotExist($this->stage($mine['id']));
        $this->assertFileExists($this->stage($other['id']));
        $this->actingAs($this->owner)->deleteJson('/testing-semester-uploads/'.$other['id'])->assertNotFound();
        $this->uploads->cancel($this->other, $other['id']);
        $this->assertFileDoesNotExist($this->stage($other['id']));
        $this->assertSame('keep', Storage::disk('local')->get('semester-backup-uploads/unrelated.txt'));
    }

    public function test_symlink_staging_paths_are_rejected_and_cancel_never_deletes_the_link_target(): void
    {
        $session = $this->uploads->start($this->owner, 'symlink.zip', 22);
        Storage::disk('public')->put('outside.txt', 'keep external content');
        unlink($this->stage($session['id']));
        symlink(Storage::disk('public')->path('outside.txt'), $this->stage($session['id']));
        $this->actingAs($this->owner)->post('/testing-semester-uploads/'.$session['id'].'/0', ['chunk' => $this->chunk(str_repeat('x', 22))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->uploads->cancel($this->owner, $session['id']);
        $this->assertSame('keep external content', Storage::disk('public')->get('outside.txt'));
    }

    public function test_owner_can_list_and_cancel_abandoned_uploads_to_free_the_active_session_limit(): void
    {
        $first = $this->uploads->start($this->owner, 'first.zip', 22);
        $this->uploads->start($this->owner, 'second.zip', 22);
        $this->uploads->start($this->owner, 'third.zip', 22);
        $foreign = $this->uploads->start($this->other, 'foreign.zip', 22);
        $this->actingAs($this->owner)->getJson('/testing-semester-uploads')->assertOk()
            ->assertJsonCount(3, 'uploads')->assertJsonMissing(['id' => $foreign['id']])
            ->assertJsonStructure(['uploads' => [['id', 'name', 'size', 'total_bytes', 'chunk_size', 'parts', 'uploaded_parts', 'uploaded_bytes', 'expires_at']]]);
        $this->postJson('/testing-semester-uploads', ['name' => 'fourth.zip', 'size' => 22])->assertUnprocessable();
        $this->deleteJson('/testing-semester-uploads/'.$first['id'])->assertOk()->assertJson(['cancelled' => true]);
        $replacement = $this->postJson('/testing-semester-uploads', ['name' => 'fourth.zip', 'size' => 22])->assertCreated()->json();
        $this->assertNotSame($first['id'], $replacement['id']);
        $this->assertCount(3, $this->uploads->list($this->owner));
        $this->assertSame([$foreign['id']], array_column($this->uploads->list($this->other), 'id'));
    }

    private function chunk(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('chunk.bin', $bytes);
    }

    private function stage(string $id): string
    {
        return Storage::disk('local')->path('semester-backup-uploads/'.$id.'/archive.zip');
    }
}
