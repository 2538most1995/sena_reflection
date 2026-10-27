<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\User;
use App\Services\SemesterBackupService;
use App\Services\SemesterBackupStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class SemesterBackupStorageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Tambon::query()->create(['id' => 'storage-t1', 'name' => 'ตำบลทดสอบ']);
    }

    public function test_downloaded_archive_can_release_images_and_restore_the_exact_thai_text_and_image_bytes(): void
    {
        $admin = User::query()->create(['id' => 'storage-admin', 'username' => 'storage-admin', 'name' => 'ผู้ดูแล', 'password' => 'password', 'role' => 'district']);
        $this->record('old-record', '1', 'ข้อความบันทึกภาษาไทย พร้อมสระและวรรณยุกต์');
        $file = $this->image('public', 'records/2569/1/archive.png');
        DB::table('record_images')->insert(['record_id' => 'old-record', 'file_path' => 'storage/'.$file['path']]);
        $backups = app(SemesterBackupService::class);
        $backup = $backups->create($admin, '2569', '1');
        $zipPath = $backups->downloadPath($backup['id']);
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();
        $backups->archive($backup['id']);
        $this->assertDatabaseMissing('records', ['id' => 'old-record']);
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release($manifest['files'], $this->resolver()));
        $this->assertSame(1, $result['deleted_count']);
        Storage::disk('public')->assertMissing($file['path']);
        $this->assertFileExists($zipPath);
        $backups->restore($backup['id']);
        $this->assertDatabaseHas('records', ['id' => 'old-record', 'result' => 'ข้อความบันทึกภาษาไทย พร้อมสระและวรรณยุกต์']);
        $this->assertSame($file['sha256'], hash_file('sha256', Storage::disk('public')->path($file['path'])));
    }

    public function test_references_in_other_records_documents_versions_revisions_layouts_drafts_settings_and_avatars_are_retained(): void
    {
        $this->record('new-record', '2');
        $files = [];
        foreach (['record', 'asset', 'document', 'version', 'revision', 'layout', 'draft', 'draft-image', 'setting', 'avatar'] as $name) {
            $files[$name] = $this->image('public', 'records/2569/1/'.$name.'.png');
        }
        DB::table('record_images')->insert(['record_id' => 'new-record', 'file_path' => 'https://example.test/sena_reflection/storage/records/2569/1/record.png?private=1']);
        $document = $this->document(['objects_json' => json_encode([['src' => '/sena_reflection/storage/records/2569/1/document.png?x=2']])]);
        DB::table('document_assets')->insert(['id' => (string) Str::uuid(), 'document_id' => $document, 'name' => 'asset.png', 'storage_path' => 'records/2569/1/asset.png', 'mime' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1]);
        DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'document_id' => $document, 'user_id' => 'owner', 'revision' => 1, 'snapshot_json' => json_encode(['src' => '/storage/records/2569/1/version.png'])]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'record_id' => 'new-record', 'revision' => 1, 'created_by' => 'owner', 'created_at' => now(), 'content_hash' => str_repeat('a', 64), 'snapshot' => json_encode(['images' => [['file_path' => '/storage/records/2569/1/revision.png']]])]);
        DB::table('record_document_layouts')->insert(['record_id' => 'new-record', 'settings' => '{}', 'content' => json_encode(['html' => '<img src="/sena_reflection/storage/records/2569/1/layout.png">'])]);
        $draft = (string) Str::uuid();
        DB::table('teaching_drafts')->insert(['id' => $draft, 'user_id' => 'owner', 'payload' => json_encode(['url' => 'https://example.test/storage/records/2569/1/draft.png'])]);
        DB::table('draft_images')->insert(['id' => (string) Str::uuid(), 'teaching_draft_id' => $draft, 'level_code' => '1', 'storage_disk' => 'public', 'storage_path' => 'records/2569/1/draft-image.png', 'original_name' => 'photo.png', 'mime_type' => 'image/png', 'width' => 1, 'height' => 1, 'size_bytes' => 1]);
        DB::table('settings')->insert(['setting_key' => 'shared-image', 'setting_value' => '<img src="/storage/records/2569/1/setting.png">']);
        User::query()->create(['id' => 'avatar-owner', 'username' => 'avatar-owner', 'name' => 'ครู', 'password' => 'password', 'role' => 'tambon', 'avatar_path' => 'storage/records/2569/1/avatar.png']);
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release(array_values($files), $this->resolver()));
        $this->assertSame(10, $result['shared_count']);
        $this->assertSame(0, $result['deleted_count']);
        foreach ($files as $file) {
            Storage::disk('public')->assertExists($file['path']);
        }
    }

    public function test_private_asset_ids_and_authenticated_image_urls_protect_files_without_path_references(): void
    {
        $asset = (string) Str::uuid();
        $document = $this->document(['content_json' => json_encode(['attrs' => ['assetId' => $asset]])]);
        $private = $this->image('local', 'documents/'.$document.'/private.png');
        $private['asset_ids'] = [$asset];
        $public = $this->image('public', 'records/2569/1/authenticated.png');
        $public['urls'] = ['/records/old/image-files/'.str_repeat('b', 64)];
        DB::table('teaching_drafts')->insert(['id' => (string) Str::uuid(), 'user_id' => 'owner', 'payload' => json_encode(['src' => 'https://example.test/sena_reflection/records/old/image-files/'.str_repeat('b', 64).'?signature=sample'])]);
        $unaliased = $this->image('local', 'documents/'.$document.'/unknown.png');
        $freePrivate = $this->image('local', 'documents/'.$document.'/free-private.png');
        $freePrivate['asset_ids'] = [(string) Str::uuid()];
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release([$private, $public, $unaliased, $freePrivate], $this->resolver()));
        $this->assertSame(3, $result['shared_count']);
        $this->assertSame(1, $result['deleted_count']);
        Storage::disk('local')->assertMissing($freePrivate['path']);
    }

    public function test_changed_missing_symlink_and_duplicate_files_are_handled_without_deleting_changed_content(): void
    {
        $free = $this->image('public', 'records/2569/1/free.png');
        $changed = $this->image('public', 'records/2569/1/changed.png');
        $modified = 'x'.substr(Storage::disk('public')->get($changed['path']), 1);
        Storage::disk('public')->put($changed['path'], $modified);
        $missing = $this->image('public', 'records/2569/1/missing.png');
        Storage::disk('public')->delete($missing['path']);
        $linked = $this->image('public', 'records/2569/1/link.png');
        Storage::disk('public')->delete($linked['path']);
        symlink(Storage::disk('public')->path($free['path']), Storage::disk('public')->path($linked['path']));
        $files = [$free, $free, $changed, $missing, $linked];
        $service = app(SemesterBackupStorageService::class);
        $plan = $service->inspect($files, $this->resolver());
        $this->assertSame(1, $plan['releasable_count']);
        $this->assertSame(2, $plan['changed_count']);
        $this->assertSame(1, $plan['missing_count']);
        $result = DB::transaction(fn (): array => $service->release($files, $this->resolver()));
        $this->assertSame(1, $result['deleted_count']);
        $this->assertSame($modified, Storage::disk('public')->get($changed['path']));
        $again = DB::transaction(fn (): array => $service->release($files, $this->resolver()));
        $this->assertSame(0, $again['deleted_count']);
        $this->assertSame(2, $again['missing_count']);
        $this->assertTrue(is_link(Storage::disk('public')->path($linked['path'])));
    }

    public function test_release_requires_a_transaction_and_rechecks_new_references_and_changed_bytes(): void
    {
        $file = $this->image('public', 'records/2569/1/referenced-late.png');
        $manager = DB::getFacadeRoot();
        DB::shouldReceive('connection')->once()->andReturn(new class
        {
            public function transactionLevel(): int
            {
                return 0;
            }
        });
        try {
            app(SemesterBackupStorageService::class)->release([$file], $this->resolver());
            $this->fail('Releasing without a transaction must fail.');
        } catch (ValidationException) {
            Storage::disk('public')->assertExists($file['path']);
        } finally {
            DB::swap($manager);
        }
        $this->record('new-record', '2');
        $called = false;
        $resolver = function (string $disk, string $path) use (&$called): string {
            if (! $called) {
                $called = true;
                DB::table('record_images')->insert(['record_id' => 'new-record', 'file_path' => 'storage/'.$path]);
            }

            return Storage::disk($disk)->path($path);
        };
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release([$file], $resolver));
        $this->assertSame(1, $result['shared_count']);
        $changed = $this->image('public', 'records/2569/1/changed-late.png');
        $calls = 0;
        $resolver = function (string $disk, string $path) use (&$calls): string {
            if (++$calls === 2) {
                Storage::disk($disk)->put($path, 'replacement during cleanup');
            }

            return Storage::disk($disk)->path($path);
        };
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release([$changed], $resolver));
        $this->assertSame(1, $result['changed_count']);
        $this->assertSame('replacement during cleanup', Storage::disk('public')->get($changed['path']));
    }

    public function test_malformed_reference_json_fails_closed_and_does_not_release_any_image(): void
    {
        $file = $this->image('public', 'records/2569/1/invalid-json.png');
        DB::table('teaching_drafts')->insert(['id' => (string) Str::uuid(), 'user_id' => 'owner', 'payload' => '{broken']);
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release([$file], $this->resolver()));
        $this->assertSame(1, $result['shared_count']);
        $this->assertSame(0, $result['deleted_count']);
    }

    public function test_legacy_duplicate_zero_image_ids_are_scanned_across_chunk_boundaries(): void
    {
        Schema::drop('record_images');
        Schema::create('record_images', function (Blueprint $table): void {
            $table->integer('id')->default(0);
            $table->string('record_id', 20);
            $table->string('file_path');
        });
        $files = [];
        for ($index = 0; $index < 151; $index++) {
            $file = $this->image('public', 'records/2569/1/reference-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.png');
            $files[] = $file;
            DB::table('record_images')->insert(['id' => 0, 'record_id' => 'old-legacy-id', 'file_path' => 'storage/'.$file['path']]);
        }
        $result = DB::transaction(fn (): array => app(SemesterBackupStorageService::class)->release($files, $this->resolver()));
        $this->assertSame(151, $result['shared_count']);
        $this->assertSame(0, $result['deleted_count']);
    }

    private function image(string $disk, string $path): array
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        Storage::disk($disk)->put($path, $bytes);

        return ['disk' => $disk, 'path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    private function resolver(): callable
    {
        return fn (string $disk, string $path): string => Storage::disk($disk)->path($path);
    }

    private function record(string $id, string $semester, string $result = 'ผลการสอน'): void
    {
        DB::table('records')->insert(['id' => $id, 'tambon' => 'storage-t1', 'year' => '2569', 'semester' => $semester, 'week' => 1, 'date' => '2026-05-13', 'subject' => 'ภาษาไทย', 'result' => $result]);
    }

    private function document(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('documents')->insert([...['id' => $id, 'user_id' => 'owner', 'title' => 'เอกสารส่วนตัว', 'content_json' => '{}', 'page_settings_json' => '{}', 'objects_json' => '[]'], ...$overrides]);

        return $id;
    }
}
