<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\DraftService;
use App\Services\SemesterBackupService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DraftLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Tambon::query()->create(['id' => 't1', 'name' => 'ตำบลหนึ่ง']);
        $this->owner = $this->user('draft-owner');
        $this->actingAs($this->owner);
    }

    public function test_pause_and_resume_preserve_private_content_files_documents_and_last_saved_time(): void
    {
        $draft = $this->draft();
        $path = 'teaching-drafts/'.$this->owner->id.'/'.$draft->id.'/original.png';
        Storage::disk('local')->put($path, 'private original image bytes');
        $image = $draft->images()->create(['level_code' => '1', 'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => 'original.png', 'mime_type' => 'image/png', 'width' => 200, 'height' => 200, 'size_bytes' => 28, 'sort_order' => 0]);
        $document = Document::query()->create(['user_id' => $this->owner->id, 'title' => 'เอกสารส่วนตัวที่จัดไว้', 'content_json' => ['anchors' => ['unchanged']], 'page_settings_json' => ['marginLeft' => 30], 'objects_json' => [], 'revision' => 4, 'binding_key' => 'draft:'.$draft->id.':1', 'binding_snapshot' => ['kind' => 'draft', 'draft_id' => $draft->id, 'level_code' => '1', 'lock_version' => 1]]);
        $payload = $draft->payload;
        $imageBefore = $image->fresh()->getRawOriginal();
        $documentBefore = $document->fresh()->getRawOriginal();
        $lastSaved = $draft->last_saved_at->toIso8601String();
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertOk()->assertJsonPath('draft.status', 'paused')->assertJsonPath('draft.lock_version', 2)->assertJsonPath('draft.last_saved_at', $lastSaved)->assertJsonPath('draft.images_count', 1);
        $this->get(route('records.create', ['draft' => $draft->id]))->assertRedirect(route('teaching-drafts.manage'))->assertSessionHas('error');
        $this->patchJson(route('teaching-drafts.update', $draft->id), ['lock_version' => 2, 'payload' => ['scope' => ['tambon' => 't1', 'year' => '2569', 'semester' => '1', 'week' => 1, 'date' => '2026-05-17']]])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->postJson(route('teaching-drafts.finalize', $draft->id), ['lock_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->getJson(route('teaching-drafts.index'))->assertOk()->assertJsonCount(0, 'data.drafts');
        $this->getJson(route('teaching-drafts.manage'))->assertOk()->assertJsonPath('drafts.0.status', 'paused');
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertOk()->assertJsonPath('draft.status', 'active')->assertJsonPath('draft.lock_version', 3);
        $this->assertSame($payload, $draft->fresh()->payload);
        $this->assertSame($imageBefore, $image->fresh()->getRawOriginal());
        $this->assertSame($documentBefore, $document->fresh()->getRawOriginal());
        $this->assertSame('private original image bytes', Storage::disk('local')->get($path));
        $this->assertSame($lastSaved, $draft->fresh()->last_saved_at->toIso8601String());
        $this->assertDatabaseCount('records', 0);
    }

    public function test_only_the_owner_can_manage_a_draft_even_for_district_accounts(): void
    {
        $draft = $this->draft();
        foreach ([$this->user('other-owner'), $this->user('district-admin', 'district')] as $other) {
            $this->actingAs($other)->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertNotFound();
            $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 1])->assertNotFound();
            $this->getJson(route('teaching-drafts.manage'))->assertOk()->assertJsonCount(0, 'drafts')->assertDontSee('private-content-marker');
        }
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'status' => 'active', 'lock_version' => 1]);
        auth()->logout();
        $this->getJson(route('teaching-drafts.manage'))->assertUnauthorized();
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertUnauthorized();
    }

    public function test_manage_list_contains_only_minimal_current_owner_metadata_without_per_draft_queries(): void
    {
        $active = $this->draft();
        $paused = $this->draft(['status' => 'paused']);
        $this->draft(['status' => 'finalized']);
        $this->draft(['user_id' => $this->user('private-other')->id]);
        for ($index = 0; $index < 8; $index++) {
            $this->draft();
        }
        DB::enableQueryLog();
        $response = $this->getJson(route('teaching-drafts.manage'))->assertOk()->assertJsonCount(10, 'drafts')->assertDontSee('private-content-marker');
        $imageQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'draft_images'));
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(2, count($imageQueries));
        $rows = $response->json('drafts');
        $this->assertContains($active->id, array_column($rows, 'id'));
        $this->assertContains($paused->id, array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertSame(['id', 'status', 'lock_version', 'scope', 'source_record_id', 'last_saved_at', 'images_count'], array_keys($row));
        }
        $this->get(route('teaching-drafts.manage'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('drafts/manage')->has('drafts', 10));
    }

    public function test_stale_lifecycle_requests_return_409_and_never_overwrite_newer_work_or_finalize(): void
    {
        $draft = $this->draft();
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 0])->assertUnprocessable();
        $draft->update(['lock_version' => 2, 'payload' => ['private' => 'newer-owner-work']]);
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertConflict()->assertJsonPath('code', 'draft_version_conflict');
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'status' => 'active', 'lock_version' => 2]);
        $this->assertSame(['private' => 'newer-owner-work'], $draft->fresh()->payload);
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 2])->assertOk()->assertJsonPath('draft.lock_version', 3);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertConflict();
        $this->assertDatabaseCount('records', 0);
        $draft->refresh()->update(['status' => 'finalized']);
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 3])->assertUnprocessable();
    }

    public function test_a_source_bound_paused_draft_can_resume_only_after_the_exact_source_is_restored(): void
    {
        $record = $this->record();
        $draft = $this->draft(['source_record_id' => $record->id, 'payload' => ['private' => 'private-content-marker', 'source_fingerprint' => app(DraftService::class)->sourceFingerprint($record)]]);
        $payload = $draft->payload;
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertOk();
        $backups = app(SemesterBackupService::class);
        $backup = $backups->create($this->user('backup-admin', 'district'), '2569', '1');
        $backups->downloadPath($backup['id']);
        $backups->archive($backup['id']);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'status' => 'paused', 'lock_version' => 2]);
        $backups->restore($backup['id']);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertOk()->assertJsonPath('draft.status', 'active')->assertJsonPath('draft.lock_version', 3);
        $this->assertSame($payload, $draft->fresh()->payload);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'present' => 0, 'result' => 'ข้อความไทยต้นฉบับ']);
    }

    public function test_changed_or_no_longer_visible_source_leaves_the_private_draft_paused(): void
    {
        $record = $this->record();
        $draft = $this->draft(['source_record_id' => $record->id, 'payload' => ['source_fingerprint' => app(DraftService::class)->sourceFingerprint($record)]]);
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertOk();
        $record->update(['result' => 'newer source work']);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertConflict()->assertJsonPath('code', 'source_record_conflict');
        $this->owner->update(['tambon' => 't2']);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'status' => 'paused', 'lock_version' => 2]);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'result' => 'newer source work']);
    }

    public function test_a_standalone_draft_cannot_recreate_an_archived_empty_period(): void
    {
        $record = $this->record();
        $draft = $this->draft();
        $this->postJson(route('teaching-drafts.pause', $draft->id), ['lock_version' => 1])->assertOk();
        $backups = app(SemesterBackupService::class);
        $backup = $backups->create($this->user('backup-admin', 'district'), '2569', '1');
        $backups->downloadPath($backup['id']);
        $backups->archive($backup['id']);
        $backups->offload($backup['id']);
        $this->postJson(route('teaching-drafts.resume', $draft->id), ['lock_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->assertDatabaseMissing('records', ['id' => $record->id]);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft->id, 'status' => 'paused', 'lock_version' => 2]);
    }

    private function user(string $id, string $role = 'tambon'): User
    {
        return User::query()->create(['id' => $id, 'name' => $id, 'username' => $id, 'password' => 'password', 'role' => $role, 'tambon' => $role === 'tambon' ? 't1' : null]);
    }

    private function draft(array $attributes = []): TeachingDraft
    {
        return TeachingDraft::query()->create([...['user_id' => $this->owner->id, 'tambon' => 't1', 'week' => 1, 'year' => '2569', 'semester' => '1', 'status' => 'active', 'lock_version' => 1, 'payload' => ['private' => 'private-content-marker'], 'last_saved_at' => now()->subHour()], ...$attributes]);
    }

    private function record(): TeachingRecord
    {
        return TeachingRecord::query()->create(['id' => 'lifecycle-source', 'tambon' => 't1', 'week' => 1, 'date' => '2026-05-17', 'year' => '2569', 'semester' => '1', 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'total' => 1, 'present' => 0, 'result' => 'ข้อความไทยต้นฉบับ', 'created_by' => $this->owner->id]);
    }
}
