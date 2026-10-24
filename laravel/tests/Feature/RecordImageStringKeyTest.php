<?php

namespace Tests\Feature;

use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordImageStringKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private TeachingRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $this->teacher = User::create([
            'id' => 'u1',
            'name' => 'ครูเสนา',
            'username' => 'teacher_sena',
            'password' => Hash::make('password123'),
            'role' => 'tambon',
            'tambon' => 't1',
        ]);
        $this->record = TeachingRecord::create([
            'id' => 'rec-001',
            'tambon' => 't1',
            'week' => 1,
            'date' => '2026-10-24',
            'semester' => '1',
            'year' => '2569',
            'level' => 'ประถมศึกษา',
            'subject' => 'คณิตศาสตร์',
            'total' => 10,
            'present' => 10,
            'created_by' => $this->teacher->id,
        ]);
    }

    private function migrateToStringImageKeys(): void
    {
        Schema::drop('record_images');
        Schema::create('record_images', static function (Blueprint $table): void {
            $table->string('id', 20)->primary();
            $table->string('record_id', 20)->index();
            $table->string('file_path');
            $table->timestamp('created_at')->nullable();
        });
        RecordImage::flushKeyTypeCache();
    }

    public function test_integer_auto_increment_schema_preserves_numeric_keys(): void
    {
        $image = RecordImage::create([
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/test-int.jpg',
        ]);

        $this->assertNotEmpty($image->id);
        $this->assertTrue(ctype_digit((string) $image->id));
        $this->assertDatabaseHas('record_images', [
            'id' => $image->id,
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/test-int.jpg',
        ]);
    }

    public function test_create_with_adaptive_key_works_on_integer_schema(): void
    {
        $image = RecordImage::createWithAdaptiveKey([
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/adaptive-int.jpg',
        ]);

        $this->assertNotEmpty($image->id);
        $this->assertTrue(ctype_digit((string) $image->id));
        $this->assertDatabaseHas('record_images', [
            'id' => $image->id,
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/adaptive-int.jpg',
        ]);
    }

    public function test_legacy_string_schema_automatically_generates_string_id(): void
    {
        $this->migrateToStringImageKeys();

        $image = RecordImage::create([
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/test-string.jpg',
        ]);

        $this->assertNotEmpty($image->id);
        $this->assertIsString($image->id);
        $this->assertTrue(str_starts_with($image->id, 'i'));
        $this->assertSame(20, strlen($image->id));
        $this->assertDatabaseHas('record_images', [
            'id' => $image->id,
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/test-string.jpg',
        ]);
    }

    public function test_legacy_string_schema_preserves_explicit_custom_id(): void
    {
        $this->migrateToStringImageKeys();

        $image = RecordImage::create([
            'id' => 'legacy-img-999',
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/explicit.jpg',
        ]);

        $this->assertSame('legacy-img-999', $image->id);
        $this->assertDatabaseHas('record_images', [
            'id' => 'legacy-img-999',
            'record_id' => $this->record->id,
            'file_path' => 'storage/records/explicit.jpg',
        ]);
    }

    public function test_multiple_images_created_on_string_schema_have_unique_ids(): void
    {
        $this->migrateToStringImageKeys();

        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $image = RecordImage::create([
                'record_id' => $this->record->id,
                'file_path' => "storage/records/photo-{$i}.jpg",
            ]);
            $this->assertTrue(str_starts_with($image->id, 'i'));
            $this->assertSame(20, strlen($image->id));
            $ids[] = $image->id;
        }

        $this->assertCount(4, array_unique($ids));
        $this->assertSame(4, $this->record->images()->count());
    }

    public function test_record_update_with_uploaded_images_works_on_string_schema(): void
    {
        $this->migrateToStringImageKeys();

        $photos = [
            UploadedFile::fake()->image('activity1.jpg', 640, 480),
            UploadedFile::fake()->image('activity2.jpg', 640, 480),
        ];

        $response = $this->actingAs($this->teacher)->put(route('records.update', $this->record), [
            'week' => 1,
            'year' => '2569',
            'semester' => '1',
            'level' => 'ประถมศึกษา',
            'date' => '2026-10-24',
            'subject' => 'คณิตศาสตร์',
            'total' => 10,
            'present' => 10,
            'images' => $photos,
        ]);

        $response->assertSessionHasNoErrors();
        $images = $this->record->images()->get();
        $this->assertCount(2, $images);
        foreach ($images as $img) {
            $this->assertTrue(str_starts_with($img->id, 'i'));
            $this->assertSame(20, strlen($img->id));
        }
    }

    public function test_legacy_api_records_update_works_with_string_schema(): void
    {
        $this->migrateToStringImageKeys();

        $pngBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

        $payload = [
            'id' => $this->record->id,
            'week' => 1,
            'year' => '2569',
            'semester' => '1',
            'level' => 'ประถมศึกษา',
            'date' => '2026-10-24',
            'subject' => 'วิทยาศาสตร์',
            'total' => 8,
            'present' => 8,
            'images' => [$pngBase64, $pngBase64],
            'attendance' => [],
        ];

        $response = $this->actingAs($this->teacher)->putJson('/api/records.php?id='.$this->record->id, $payload);
        $response->assertOk();

        $images = $this->record->images()->get();
        $this->assertCount(2, $images);
        foreach ($images as $img) {
            $this->assertTrue(str_starts_with($img->id, 'i'));
            $this->assertSame(20, strlen($img->id));
        }
    }
}
