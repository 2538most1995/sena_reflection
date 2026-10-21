<?php

namespace Tests\Feature;

use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\RecordImageAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecordImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private TeachingRecord $record;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/sena-image-delivery-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->fixtureRoot.'/uploads', 0700, true);
        $this->app->usePublicPath($this->fixtureRoot);
        Storage::fake('public');
        Tambon::create(['id' => 'image-t1', 'name' => 'เสนา']);
        Tambon::create(['id' => 'image-t2', 'name' => 'สามกอ']);
        $this->teacher = User::create(['id' => 'image-u1', 'username' => 'image-teacher', 'name' => 'ครูตัวอย่าง', 'password' => 'not-used', 'role' => 'tambon', 'tambon' => 'image-t1']);
        $this->record = TeachingRecord::create([
            'id' => 'image-record', 'tambon' => 'image-t1', 'week' => 1, 'date' => '2026-10-21',
            'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'ภาษาไทย',
            'total' => 2, 'present' => 1, 'created_by' => $this->teacher->id,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_all_four_legacy_images_resolve_the_same_upload_paths_as_the_print_renderer(): void
    {
        $paths = [
            'uploads/image-1.png',
            '/sena_reflection/uploads/image-2.png',
            'https://school.example/sena_reflection/uploads/image-3.png?version=3',
            '\\sena_reflection\\uploads\\image-4.png',
        ];
        foreach ($paths as $index => $path) {
            $fileName = 'image-'.($index + 1).'.png';
            File::put($this->fixtureRoot.'/uploads/'.$fileName, $this->png());
            $image = RecordImage::create(['record_id' => $this->record->id, 'file_path' => $path]);
            $response = $this->actingAs($this->teacher)->get(route('records.images.show', [$this->record, $image]));
            $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame(realpath($this->fixtureRoot.'/uploads/'.$fileName), $response->baseResponse->getFile()->getRealPath());
        }
    }

    public function test_encoded_legacy_prefixes_and_separators_resolve_before_boundary_checks(): void
    {
        File::put($this->fixtureRoot.'/uploads/encoded photo.png', $this->png());
        $paths = [
            '/sena_reflection%2Fuploads%2Fencoded%20photo.png',
            '%5Csena_reflection%5Cuploads%5Cencoded%20photo.png',
            'https://school.example/sena_reflection%2fuploads%2fencoded%20photo.png?version=2',
        ];
        foreach ($paths as $path) {
            $image = RecordImage::create(['record_id' => $this->record->id, 'file_path' => $path]);
            $response = $this->actingAs($this->teacher)->get(route('records.images.show', [$this->record, $image]));
            $response->assertOk();
            $this->assertSame(realpath($this->fixtureRoot.'/uploads/encoded photo.png'), $response->baseResponse->getFile()->getRealPath());
        }
    }

    public function test_laravel_storage_images_are_served_without_a_public_storage_symlink(): void
    {
        Storage::disk('public')->put('records/photo.png', $this->png());
        $image = RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'storage/records/photo.png']);
        $response = $this->actingAs($this->teacher)->get(route('records.images.show', [$this->record, $image]));
        $response->assertOk();
        $this->assertSame(Storage::disk('public')->path('records/photo.png'), $response->baseResponse->getFile()->getPathname());
    }

    public function test_print_and_authenticated_delivery_share_storage_url_and_encoded_path_normalization(): void
    {
        Storage::disk('public')->put('records/shared photo.png', $this->png());
        foreach ([
            '/sena_reflection/storage/records/shared%20photo.png',
            'https://school.example/sena_reflection/storage/records/shared%20photo.png?v=3',
            '/sena_reflection%2fstorage%2frecords%2fshared%20photo.png',
            '%5Csena_reflection%5Cstorage%5Crecords%5Cshared%20photo.png',
        ] as $path) {
            $this->assertSame('storage/records/shared photo.png', RecordImageAssets::normalizeStoredPath($path));
            $image = RecordImage::create(['record_id' => $this->record->id, 'file_path' => $path]);
            $this->actingAs($this->teacher)->get(route('records.images.show', [$this->record, $image]))->assertOk();
            $this->assertSame('data:image/png;base64,'.base64_encode($this->png()), app(RecordImageAssets::class)->previewSrc($path));
        }
        foreach (['', 'storage/%2e%2e/private.png', 'storage%2f..%5cprivate.png', "uploads/photo\0.png"] as $path) {
            $this->assertNull(RecordImageAssets::normalizeStoredPath($path));
        }
    }

    public function test_legacy_path_normalization_keeps_auth_membership_and_file_boundaries(): void
    {
        File::put($this->fixtureRoot.'/uploads/photo.png', $this->png());
        $image = RecordImage::create(['record_id' => $this->record->id, 'file_path' => '/sena_reflection/uploads/photo.png']);
        $url = route('records.images.show', [$this->record, $image]);
        $this->get($url)->assertRedirect(route('login'));
        $other = User::create(['id' => 'image-u2', 'username' => 'other-image-teacher', 'name' => 'ครูอื่น', 'password' => 'not-used', 'role' => 'tambon', 'tambon' => 'image-t2']);
        $this->actingAs($other)->get($url)->assertForbidden();
        $otherRecord = TeachingRecord::create([
            'id' => 'other-image-record', 'tambon' => 'image-t1', 'week' => 2, 'date' => '2026-10-21',
            'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'ภาษาไทย',
            'total' => 2, 'present' => 1, 'created_by' => $this->teacher->id,
        ]);
        $this->actingAs($this->teacher)->get(route('records.images.show', [$otherRecord, $image]))->assertNotFound();
        File::put($this->fixtureRoot.'/private.png', $this->png());
        foreach (['uploads/../private.png', 'uploads/%2e%2e/private.png', 'uploads%2f..%5cprivate.png', 'uploads%5c%2e%2e%5cprivate.png', 'uploads/missing.png'] as $path) {
            $image->update(['file_path' => $path]);
            $this->get($url)->assertNotFound();
        }
    }

    public function test_record_details_embed_all_four_authenticated_images_instead_of_relying_on_rewrite_urls(): void
    {
        $this->withoutVite();
        $paths = ['uploads/preview-1.png', '/sena_reflection/uploads/preview-2.png', 'https://school.example/sena_reflection/uploads/preview-3.png?v=2', '%5Csena_reflection%5Cuploads%5Cpreview-4.png'];
        foreach ($paths as $index => $path) {
            File::put($this->fixtureRoot.'/uploads/preview-'.($index + 1).'.png', $this->png());
            RecordImage::create(['record_id' => $this->record->id, 'file_path' => $path]);
        }

        $response = $this->actingAs($this->teacher)->get(route('records.show', $this->record));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('records/show')->has('record.images', 4));
        foreach ($response->inertiaProps('record.images') as $image) {
            $this->assertSame('data:image/png;base64,'.base64_encode($this->png()), $image['preview_src']);
        }
    }

    public function test_storage_previews_and_missing_images_do_not_require_a_public_storage_symlink(): void
    {
        $this->withoutVite();
        Storage::disk('public')->put('records/preview.png', $this->png());
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => '/sena_reflection/storage/records/preview.png']);
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/missing.png']);
        File::put($this->fixtureRoot.'/uploads/not-image.png', 'This is not an image');
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/not-image.png']);
        $response = $this->actingAs($this->teacher)->get(route('records.show', $this->record));
        $response->assertOk();
        $images = $response->inertiaProps('record.images');
        $this->assertStringStartsWith('data:image/png;base64,', $images[0]['preview_src']);
        $this->assertNull($images[1]['preview_src']);
        $this->assertNull($images[2]['preview_src']);
    }

    public function test_large_previews_are_resized_in_memory_without_changing_the_uploaded_image(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is optional; original bounded image bytes remain the preview fallback.');
        }
        $this->withoutVite();
        $image = imagecreatetruecolor(1600, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 35, 100, 80));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);
        File::put($this->fixtureRoot.'/uploads/large.png', $bytes);
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/large.png']);

        $response = $this->actingAs($this->teacher)->get(route('records.show', $this->record));
        $response->assertOk();
        $encoded = $response->inertiaProps('record.images.0.preview_src');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $encoded);
        $resized = getimagesizefromstring(base64_decode(explode(',', $encoded, 2)[1]));
        $this->assertSame([640, 320], [$resized[0], $resized[1]]);
        $this->assertSame($bytes, File::get($this->fixtureRoot.'/uploads/large.png'));
    }

    public function test_previews_do_not_embed_oversized_files_or_expose_other_tambon_records(): void
    {
        $this->withoutVite();
        $file = $this->fixtureRoot.'/uploads/oversized.png';
        $handle = fopen($file, 'wb');
        fwrite($handle, $this->png());
        ftruncate($handle, 8 * 1024 * 1024 + 1);
        fclose($handle);
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/oversized.png']);
        $response = $this->actingAs($this->teacher)->get(route('records.show', $this->record));
        $response->assertOk();
        $this->assertNull($response->inertiaProps('record.images.0.preview_src'));
        $other = User::create(['id' => 'preview-other', 'username' => 'preview-other', 'name' => 'ครูอื่น', 'password' => 'not-used', 'role' => 'tambon', 'tambon' => 'image-t2']);
        $this->actingAs($other)->get(route('records.show', $this->record))->assertForbidden()->assertDontSee('data:image');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6wWQAAAAASUVORK5CYII=');
    }
}
