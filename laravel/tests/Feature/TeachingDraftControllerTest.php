<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\Document;
use App\Models\RecordAttendance;
use App\Models\RecordDocumentLayout;
use App\Models\RecordImage;
use App\Models\RecordRevision;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\DraftService;
use App\Services\LegacyPrintService;
use App\Services\RecordRevisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeachingDraftControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_autosaves_and_resumes_an_owned_draft(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();

        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])
            ->assertCreated()
            ->assertJsonPath('data.draft.lock_version', 1)
            ->assertJsonPath('data.draft.payload.levels.1.subject', 'ภาษาไทย');
        $draftId = $created->json('data.draft.id');
        $payload['levels']['1']['subject'] = 'ภาษาไทยฉบับแก้ไข';

        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.draft.lock_version', 2)
            ->assertJsonPath('data.draft.payload.levels.1.subject', 'ภาษาไทยฉบับแก้ไข');
        $this->getJson(route('teaching-drafts.show', $draftId))
            ->assertOk()
            ->assertJsonPath('data.draft.payload.levels.1.subject', 'ภาษาไทยฉบับแก้ไข');
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'user_id' => 'u1', 'lock_version' => 2]);
    }

    public function test_finalizes_orientation_without_requiring_or_inventing_a_subject_code(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $payload['levels']['1']['subject'] = 'ปฐมนิเทศ';
        $payload['levels']['1']['subject_code'] = '';
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');

        $response = $this->postJson(route('teaching-drafts.finalize', $id), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true,
        ])->assertOk()->assertJsonCount(1, 'data.record_ids');

        $this->assertDatabaseHas('records', ['id' => $response->json('data.record_ids.0'), 'subject' => 'ปฐมนิเทศ', 'subject_code' => '']);
        $this->assertDatabaseCount('records', 1);
    }

    public function test_resuming_a_finalized_draft_returns_to_its_saved_week_without_opening_an_editing_draft(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true,
        ])->assertOk();

        $this->get(route('records.create', ['draft' => $draftId]))
            ->assertRedirect(route('dashboard', ['year' => $payload['scope']['year'], 'semester' => $payload['scope']['semester'], 'week' => $payload['scope']['week']]));

        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'status' => 'finalized']);
    }

    public function test_returns_409_and_latest_draft_when_autosave_version_is_stale(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        $firstPayload = $payload;
        $firstPayload['levels']['1']['subject'] = 'ข้อมูลจากแท็บแรก';
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $firstPayload])->assertOk();
        $stalePayload = $payload;
        $stalePayload['levels']['1']['subject'] = 'ข้อมูลจากแท็บเก่า';

        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $stalePayload])
            ->assertConflict()
            ->assertJsonPath('code', 'draft_version_conflict')
            ->assertJsonPath('data.draft.lock_version', 2)
            ->assertJsonPath('data.draft.payload.levels.1.subject', 'ข้อมูลจากแท็บแรก');
        $this->assertDatabaseMissing('teaching_drafts', ['id' => $draftId, 'payload' => json_encode($stalePayload)]);
    }

    public function test_returns_404_when_another_user_or_district_reads_a_private_draft(): void
    {
        $owner = $this->createTeacher('u1', 't1');
        $other = $this->createTeacher('u2', 't2');
        $district = User::query()->create([
            'id' => 'district', 'name' => 'ผู้ดูแล', 'username' => 'district',
            'password' => Hash::make('secret'), 'role' => 'district', 'tambon' => null,
        ]);
        $draftId = $this->actingAs($owner)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload('t1')])->json('data.draft.id');

        $this->actingAs($other)->getJson(route('teaching-drafts.show', $draftId))->assertNotFound();
        $this->actingAs($district)->getJson(route('teaching-drafts.show', $draftId))->assertNotFound();
    }

    public function test_uploads_reorders_and_deletes_private_draft_images_with_version_updates(): void
    {
        Storage::fake('local');
        $user = $this->createTeacher('u1', 't1');
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');

        $first = $this->post(route('teaching-drafts.images.store', $draftId), [
            'lock_version' => 1, 'level_code' => '1', 'image' => UploadedFile::fake()->image('one.jpg', 300, 300),
        ])->assertCreated()->assertJsonPath('data.lock_version', 2);
        $firstId = $first->json('data.image.id');
        $this->assertArrayNotHasKey('storage_path', $first->json('data.image'));
        $second = $this->post(route('teaching-drafts.images.store', $draftId), [
            'lock_version' => 2, 'level_code' => '1', 'image' => UploadedFile::fake()->image('two.jpg', 300, 300),
        ])->assertCreated()->assertJsonPath('data.lock_version', 3);
        $secondId = $second->json('data.image.id');
        $this->getJson(route('teaching-drafts.index'))->assertOk()
            ->assertJsonPath('data.drafts.0.images_count', 2)
            ->assertJsonPath('data.drafts.0.progress.completed_levels', 3);

        $this->patchJson(route('teaching-drafts.images.reorder', $draftId), [
            'lock_version' => 3, 'level_code' => '1', 'image_ids' => [$secondId, $firstId],
        ])->assertOk()->assertJsonPath('data.lock_version', 4)->assertJsonPath('data.images.0.id', $secondId);
        $this->deleteJson(route('teaching-drafts.images.destroy', [$draftId, $firstId]), ['lock_version' => 4])
            ->assertOk()->assertJsonPath('data.lock_version', 5)->assertJsonCount(1, 'data.images');
        $this->assertDatabaseCount('draft_images', 1);
    }

    public function test_finalize_saves_three_levels_atomically_captures_revisions_and_is_idempotent(): void
    {
        Storage::fake('public');
        $user = $this->createTeacher('u1', 't1');
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $key = (string) Str::uuid();

        $first = $this->postJson(route('teaching-drafts.finalize', $draftId), ['lock_version' => 1, 'idempotency_key' => $key])
            ->assertOk()
            ->assertJsonCount(3, 'data.record_ids');
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseCount('record_revisions', 3);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'status' => 'finalized', 'finalize_key' => $key]);

        $this->postJson(route('teaching-drafts.finalize', $draftId), ['lock_version' => 1, 'idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('data.submission_id', $first->json('data.submission_id'));
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseCount('record_revisions', 3);
    }

    public function test_consolidated_confirmation_saves_unreviewed_roster_without_pdf_and_preserves_actual_attendance(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $payload['levels']['1'] = [...$payload['levels']['1'],
            'attendance_mode' => 'roster', 'attendance_reviewed' => false,
            'document' => ['settings' => ['pdf_margin_left_px' => '94']],
            'attendance' => [
                ['student_id' => 'S01', 'full_name' => 'ผู้เรียนที่ไม่มา', 'group_code' => 'G1', 'present' => false, 'learning_method' => null],
                ['student_id' => 'S02', 'full_name' => 'ผู้เรียนออนไลน์', 'group_code' => 'G1', 'present' => true, 'learning_method' => 'ออนไลน์'],
            ],
        ];
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $this->getJson(route('teaching-drafts.index'))->assertOk()->assertJsonPath('data.drafts.0.progress.completed_levels', 1);
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldNotReceive('snapshotPdf');
        $print->shouldNotReceive('browserSnapshotHtml');
        $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.levels.1.attendance');
        Cache::put(DraftService::reviewCacheKey($id, 1, '1'), ['pdf_margin_left_px' => '120'], now()->addHour());
        $key = (string) Str::uuid();
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => $key, 'confirmed' => true])
            ->assertOk()->assertJsonCount(1, 'data.record_ids');
        $record = TeachingRecord::query()->findOrFail($confirmed->json('data.record_ids.0'));
        $this->assertSame(2, $record->total);
        $this->assertSame(1, $record->present);
        $this->assertSame('94', $record->documentLayout->settings['pdf_margin_left_px']);
        $this->assertDatabaseHas('record_attendance', ['record_id' => $record->id, 'student_id' => 'S01', 'present' => false, 'learning_method' => null]);
        $this->assertDatabaseHas('record_attendance', ['record_id' => $record->id, 'student_id' => 'S02', 'present' => true, 'learning_method' => 'ออนไลน์']);
        $this->assertFalse(TeachingDraft::query()->findOrFail($id)->payload['levels']['1']['attendance_reviewed']);
        $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => $key, 'confirmed' => true])
            ->assertOk()->assertJsonPath('data.record_ids', [$record->id]);
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('record_attendance', 2);
    }

    public function test_explicit_consolidated_confirmation_must_be_accepted_when_supplied(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->assertCreated()->json('data.draft.id');
        foreach ([false, null, 0, 'false', 'no', [], 'not-confirmed'] as $value) {
            $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        }
        $this->assertDatabaseHas('teaching_drafts', ['id' => $id, 'status' => 'active', 'lock_version' => 1]);
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('weekly_submissions', 0);
    }

    public function test_consolidated_confirmation_does_not_bypass_data_validation_or_report_invalid_drafts_as_ready(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $base = $this->draftPayload();
        $base['enabled_level_codes'] = ['1'];
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $base])->assertCreated()->json('data.draft.id');
        $draft = TeachingDraft::query()->findOrFail($id);
        $row = ['student_id' => 'S01', 'full_name' => 'ผู้เรียน', 'group_code' => 'G1', 'present' => false, 'learning_method' => null];
        $cases = [
            [['attendance_mode' => 'roster', 'attendance' => []], 'payload.levels.1.attendance'],
            [['attendance_mode' => 'roster', 'attendance' => [$row, $row]], 'payload.levels.1.attendance'],
            [['attendance_mode' => 'roster', 'attendance' => [[...$row, 'group_code' => 'other-group']]], 'payload.levels.1.attendance'],
            [['attendance_mode' => 'roster', 'attendance' => [[...$row, 'student_id' => '']]], 'payload.levels.1.attendance.0.student_id'],
            [['attendance_mode' => 'roster', 'attendance' => [[...$row, 'present' => true, 'learning_method' => 'วิธีที่ไม่รองรับ']]], 'payload.levels.1.attendance.0.learning_method'],
            [['attendance_mode' => 'counts', 'total' => -1, 'present' => 0], 'payload.levels.1.total'],
            [['attendance_mode' => 'counts', 'total' => 2, 'present' => 3], 'payload.levels.1.present'],
            [['result' => ''], 'payload.levels.1.result'],
        ];
        foreach ($cases as [$changes, $error]) {
            $payload = $base;
            $payload['levels']['1'] = [...$payload['levels']['1'], ...$changes];
            $draft->update(['payload' => $payload]);
            $this->getJson(route('teaching-drafts.index'))->assertOk()->assertJsonPath('data.drafts.0.progress.completed_levels', 0);
            $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])
                ->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->assertDatabaseCount('records', 0);
            $this->assertDatabaseCount('weekly_submissions', 0);
            $this->assertSame('active', $draft->fresh()->status);
        }
    }

    public function test_consolidated_confirmation_preserves_owner_scope_stale_locks_and_duplicate_protection(): void
    {
        $owner = $this->createTeacher('u1', 't1');
        $other = $this->createTeacher('u2', 't2');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $id = $this->actingAs($owner)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $this->actingAs($other)->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])->assertNotFound();
        $payload['levels']['1']['result'] = 'ฉบับล่าสุด';
        $this->actingAs($owner)->patchJson(route('teaching-drafts.update', $id), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])
            ->assertConflict()->assertJsonPath('code', 'draft_version_conflict');
        $this->assertDatabaseCount('records', 0);
        $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])->assertOk();
        $second = $this->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $this->postJson(route('teaching-drafts.finalize', $second), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.scope.week');
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('weekly_submissions', 1);
    }

    public function test_finalize_saves_only_enabled_levels_when_some_levels_are_disabled(): void
    {
        Storage::fake('public');
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        // Teacher teaches only ประถมศึกษา ('1') and มัธยมศึกษาตอนปลาย ('3') - no ม.ต้น ('2')
        $payload['enabled_level_codes'] = ['1', '3'];
        unset($payload['levels']['2']);
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        $key = (string) Str::uuid();

        $this->postJson(route('teaching-drafts.finalize', $draftId), ['lock_version' => 1, 'idempotency_key' => $key])
            ->assertOk()
            ->assertJsonCount(2, 'data.record_ids');
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 2);
        $this->assertDatabaseMissing('records', ['level' => 'มัธยมศึกษาตอนต้น']);
        $this->assertDatabaseHas('records', ['level' => 'ประถมศึกษา']);
        $this->assertDatabaseHas('records', ['level' => 'มัธยมศึกษาตอนปลาย']);
    }

    public function test_finalize_rejects_an_incomplete_draft_without_partial_records(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        unset($payload['levels']['3']);
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');

        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.levels');
        $this->assertDatabaseCount('weekly_submissions', 0);
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'status' => 'active']);
    }

    public function test_preview_uses_the_owned_server_snapshot_and_returns_pdf(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->withArgs(function (array $snapshot, User $previewUser): bool {
            return $snapshot['record']['subject'] === 'ภาษาไทย'
                && $snapshot['record']['level'] === 'ประถมศึกษา'
                && $snapshot['images'] === []
                && $previewUser->id === 'u1';
        })->andReturn('%PDF-draft');

        $this->postJson(route('teaching-drafts.preview', $draftId), ['lock_version' => 1, 'level_code' => '1'])
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_changing_the_week_of_a_source_draft_creates_new_records_and_preserves_the_previous_week(): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->createTeacher('u1', 't1');
        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()]);
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $created->json('data.draft.id')), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $ids = $confirmed->json('data.record_ids');
        $source = TeachingRecord::query()->findOrFail($ids[0]);
        $source->update(['total' => 2, 'present' => 1]);
        foreach ([true, false] as $index => $present) {
            RecordAttendance::create([
                'record_id' => $source->id, 'student_id' => 'S'.$index, 'full_name' => 'ผู้เรียน '.$index,
                'group_code' => 'G1', 'present' => $present, 'learning_method' => $present ? 'ออนไลน์' : null,
            ]);
            $path = 'records/source-'.$index.'.jpg';
            $file = UploadedFile::fake()->image('source.jpg');
            Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
            RecordImage::create(['record_id' => $source->id, 'file_path' => 'storage/'.$path]);
        }
        $source->documentLayout->update(['settings' => ['pdf_margin_left_px' => '120']]);
        $document = Document::factory()->create([
            'user_id' => $user->id, 'source_record_id' => $source->id, 'title' => 'เอกสารต้นฉบับที่จัดไว้',
            'binding_key' => 'record:'.$source->id, 'binding_snapshot' => ['kind' => 'record', 'record_id' => $source->id],
        ]);
        $originalDocument = $document->fresh()->attributesToArray();
        $originalFingerprint = app(DraftService::class)->sourceFingerprint($source);
        $originalImageHashes = $source->images()->get()->mapWithKeys(fn (RecordImage $image): array => [
            $image->file_path => hash('sha256', Storage::disk('public')->get(substr($image->file_path, 8))),
        ])->all();
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $ids[0]])->assertCreated();
        $draftId = $editing->json('data.draft.id');
        $payload = $editing->json('data.draft.payload');
        $payload['scope']['week'] = 11;
        $payload['levels']['1']['result'] = 'ผลการสอนสัปดาห์ใหม่';
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $key = (string) Str::uuid();

        $next = $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 2, 'idempotency_key' => $key, 'confirmed' => true,
        ])->assertOk()->assertJsonCount(3, 'data.record_ids');

        $this->assertDatabaseCount('records', 6);
        $this->assertDatabaseCount('weekly_submissions', 2);
        $this->assertDatabaseHas('weekly_submissions', ['id' => $confirmed->json('data.submission_id'), 'week' => 10]);
        $this->assertNotSame($confirmed->json('data.submission_id'), $next->json('data.submission_id'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('records', ['id' => $id, 'week' => 10, 'result' => 'เรียนรู้ตามแผน']);
            $this->assertNotContains($id, $next->json('data.record_ids'));
        }
        $this->assertSame(3, TeachingRecord::query()->where('week', 11)->count());
        $this->assertDatabaseHas('records', ['id' => $next->json('data.record_ids.0'), 'result' => 'ผลการสอนสัปดาห์ใหม่']);
        $this->assertDatabaseCount('record_revisions', 6);
        $this->assertSame($originalFingerprint, app(DraftService::class)->sourceFingerprint($source->fresh()));
        $this->assertSame($originalDocument, $document->fresh()->attributesToArray());
        foreach ($originalImageHashes as $path => $hash) {
            Storage::disk('public')->assertExists(substr($path, 8));
            $this->assertSame($hash, hash('sha256', Storage::disk('public')->get(substr($path, 8))));
        }
        $newRecord = TeachingRecord::query()->findOrFail($next->json('data.record_ids.0'));
        $this->assertSame(2, $newRecord->images()->count());
        $this->assertSame(2, $newRecord->attendance()->count());
        $this->assertSame(1, $newRecord->present);
        $this->assertSame('120', $newRecord->documentLayout->settings['pdf_margin_left_px']);
        $this->assertDatabaseHas('record_attendance', ['record_id' => $newRecord->id, 'student_id' => 'S0', 'present' => true, 'learning_method' => 'ออนไลน์']);
        $this->assertDatabaseHas('record_attendance', ['record_id' => $newRecord->id, 'student_id' => 'S1', 'present' => false, 'learning_method' => null]);
        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 2, 'idempotency_key' => $key, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.record_ids', $next->json('data.record_ids'));
        $this->getJson(route('teaching-drafts.index'))->assertOk()->assertJsonCount(0, 'data.drafts');
        $this->assertDatabaseCount('records', 6);
    }

    #[DataProvider('changedSourceScopes')]
    public function test_changing_the_source_term_or_tambon_creates_a_separate_submission(array $scopeChanges): void
    {
        Http::preventStrayRequests();
        $teacher = $this->createTeacher('u1', 't1');
        $this->createTeacher('u2', 't2');
        $district = User::query()->create([
            'id' => 'district', 'name' => 'ผู้ดูแล', 'username' => 'district',
            'password' => Hash::make('secret'), 'role' => 'district', 'tambon' => null,
        ]);
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $draftId = $this->actingAs($teacher)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $original = $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'confirmed' => true, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $source = TeachingRecord::query()->findOrFail($original->json('data.record_ids.0'));
        $fingerprint = app(DraftService::class)->sourceFingerprint($source);
        $editing = $this->actingAs($district)->postJson(route('teaching-drafts.store'), ['source_record_id' => $source->id])->assertCreated();
        $editId = $editing->json('data.draft.id');
        $editPayload = $editing->json('data.draft.payload');
        $editPayload['scope'] = [...$editPayload['scope'], ...$scopeChanges];
        $this->patchJson(route('teaching-drafts.update', $editId), ['lock_version' => 1, 'payload' => $editPayload])->assertOk();

        $saved = $this->postJson(route('teaching-drafts.finalize', $editId), [
            'lock_version' => 2, 'confirmed' => true, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonCount(1, 'data.record_ids');

        $this->assertDatabaseCount('records', 2);
        $this->assertDatabaseCount('weekly_submissions', 2);
        $this->assertNotSame($source->id, $saved->json('data.record_ids.0'));
        $this->assertSame($fingerprint, app(DraftService::class)->sourceFingerprint($source->fresh()));
        $this->assertDatabaseHas('records', ['id' => $saved->json('data.record_ids.0'), ...$scopeChanges]);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function changedSourceScopes(): array
    {
        return [
            'semester' => [['semester' => '2']],
            'year' => [['year' => '2570']],
            'tambon' => [['tambon' => 't2']],
        ];
    }

    public function test_returns_422_without_overwriting_a_target_week_when_a_legacy_source_draft_changes_week(): void
    {
        Http::preventStrayRequests();
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['scope']['week'] = 11;
        $payload['enabled_level_codes'] = ['1'];
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $target = $this->postJson(route('teaching-drafts.finalize', $id), [
            'lock_version' => 1, 'confirmed' => true, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $targetRecord = TeachingRecord::query()->findOrFail($target->json('data.record_ids.0'));
        $source = TeachingRecord::create([
            'id' => 'legacy-original', 'tambon' => 't1', 'week' => 10, 'date' => '2026-10-16',
            'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'ต้นฉบับเดิม',
            'class_group_code' => 'G1', 'class_name' => 'กลุ่ม 1', 'total' => 2, 'present' => 1,
            'result' => 'เนื้อหาที่ต้องรักษา', 'created_by' => $user->id,
        ]);
        $sourceFingerprint = app(DraftService::class)->sourceFingerprint($source);
        $targetFingerprint = app(DraftService::class)->sourceFingerprint($targetRecord);
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $source->id])->assertCreated();
        $editId = $editing->json('data.draft.id');
        $editPayload = $editing->json('data.draft.payload');
        $editPayload['scope']['week'] = 11;
        $this->patchJson(route('teaching-drafts.update', $editId), ['lock_version' => 1, 'payload' => $editPayload])->assertOk();

        $rejected = $this->postJson(route('teaching-drafts.finalize', $editId), [
            'lock_version' => 2, 'confirmed' => true, 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.scope.week');

        $this->assertSame('ระดับชั้นที่เลือกมีบันทึกในสัปดาห์นี้แล้ว กรุณาเปิดรายการเดิมเพื่อแก้ไข', $rejected->json('errors')['payload.scope.week'][0]);
        $this->assertDatabaseCount('records', 2);
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertSame($sourceFingerprint, app(DraftService::class)->sourceFingerprint($source->fresh()));
        $this->assertSame($targetFingerprint, app(DraftService::class)->sourceFingerprint($targetRecord->fresh()));
        $this->assertDatabaseHas('teaching_drafts', ['id' => $editId, 'status' => 'active', 'lock_version' => 2]);
    }

    public function test_returns_409_when_source_changes_and_can_start_a_fresh_draft_without_losing_the_old_one(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()]);
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $created->json('data.draft.id')), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $recordId = $confirmed->json('data.record_ids.0');
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $recordId])->assertCreated();
        $draftId = $editing->json('data.draft.id');
        TeachingRecord::query()->whereKey($recordId)->update(['subject' => 'วิชาจากอีกหน้าต่าง']);

        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'source_record_conflict')->assertJsonPath('data.draft.id', $draftId);
        $fresh = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $recordId, 'refresh_source' => true])
            ->assertCreated()->assertJsonPath('data.draft.payload.levels.1.subject', 'วิชาจากอีกหน้าต่าง');

        $this->assertNotSame($draftId, $fresh->json('data.draft.id'));
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'status' => 'active']);
        $this->assertDatabaseHas('records', ['id' => $recordId, 'subject' => 'วิชาจากอีกหน้าต่าง']);
    }

    public function test_preview_and_finalization_use_roster_totals_instead_of_unrelated_counts(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['levels']['1'] = [...$payload['levels']['1'],
            'attendance_mode' => 'roster', 'attendance_reviewed' => true,
            'attendance' => [
                ['student_id' => 'S01', 'full_name' => 'ผู้เรียนหนึ่ง', 'group_code' => 'G1', 'present' => 1],
                ['student_id' => 'S02', 'full_name' => 'ผู้เรียนสอง', 'group_code' => 'G1', 'present' => 0],
            ],
        ];
        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated();
        $draftId = $created->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldReceive('snapshotPdf')->once()
            ->withArgs(fn (array $snapshot): bool => $snapshot['record']['total'] === 2
                && $snapshot['record']['present'] === 1 && count($snapshot['attendance']) === 2)
            ->andReturn('%PDF-roster');

        $this->postJson(route('teaching-drafts.preview', $draftId), ['lock_version' => 1, 'level_code' => '1'])->assertOk();
        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertDatabaseHas('records', ['level' => 'ประถมศึกษา', 'total' => 2, 'present' => 1]);
        $this->assertDatabaseCount('record_attendance', 2);
        $this->assertDatabaseHas('record_attendance', ['student_id' => 'S02', 'present' => false, 'learning_method' => null]);
    }

    #[DataProvider('imageKeySchemas')]
    public function test_district_can_confirm_an_imported_single_level_record_with_26_roster_rows_and_four_photos(bool $stringImageKeys): void
    {
        Storage::fake('local');
        Storage::fake('public');
        if ($stringImageKeys) {
            Schema::drop('record_images');
            Schema::create('record_images', static function (Blueprint $table): void {
                $table->string('id', 20)->primary();
                $table->string('record_id', 20)->index();
                $table->string('file_path');
                $table->timestamp('created_at')->nullable();
            });
        }
        $teacher = $this->createTeacher('source-teacher', 't1');
        $district = User::create(['id' => 'district', 'name' => 'ผู้ดูแลอำเภอ', 'username' => 'district', 'password' => Hash::make('secret'), 'role' => 'district', 'tambon' => null]);
        $source = TeachingRecord::create(['id' => 'source-level-three', 'tambon' => 't1', 'week' => 16, 'date' => '2026-10-23', 'semester' => '1', 'year' => '2569', 'level' => 'มัธยมศึกษาตอนปลาย', 'subject' => 'ภาษาไทย', 'class_group_code' => 'G3', 'class_name' => 'กลุ่ม 3', 'total' => 26, 'present' => 7, 'result' => 'ผลการจัดการเรียนรู้เดิม', 'created_by' => $teacher->id]);
        for ($index = 1; $index <= 26; $index++) {
            RecordAttendance::create(['record_id' => $source->id, 'student_id' => '00'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'full_name' => ' ผู้เรียน '.$index.' ', 'group_code' => ' G3 ', 'class_name' => ' กลุ่ม 3 ', 'present' => $index <= 7 ? 1 : 0, 'learning_method' => $index <= 7 ? '  ' : 'ออนไลน์']);
        }
        $layouts = [];
        foreach (range(1, 4) as $index) {
            $file = UploadedFile::fake()->image('source-'.$index.'.jpg', 300, 300);
            $path = 'records/source-'.$index.'.jpg';
            Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
            $image = RecordImage::create(['record_id' => $source->id, 'file_path' => 'storage/'.$path]);
            $layouts[(string) $image->id] = ['width_px' => (string) (250 + $index * 20), 'height_px' => '180', 'x_px' => (string) (($index - 1) * 30), 'y_px' => '8'];
        }
        RecordDocumentLayout::create(['record_id' => $source->id, 'settings' => ['image_layout_by_id' => json_encode($layouts, JSON_THROW_ON_ERROR)], 'content' => [], 'updated_by' => $teacher->id]);
        $created = $this->actingAs($district)->postJson(route('teaching-drafts.store'), ['source_record_id' => $source->id])->assertCreated();
        $id = $created->json('data.draft.id');
        $created->assertJsonPath('data.draft.payload.enabled_level_codes', ['3'])->assertJsonCount(26, 'data.draft.payload.levels.3.attendance')->assertJsonCount(4, 'data.draft.payload.levels.3.image_ids');
        $created->assertJsonPath('data.draft.payload.levels.3.attendance.0.present', true)->assertJsonPath('data.draft.payload.levels.3.attendance.0.learning_method', 'พบกลุ่ม')
            ->assertJsonPath('data.draft.payload.levels.3.attendance.25.present', false)->assertJsonPath('data.draft.payload.levels.3.attendance.25.learning_method', null)
            ->assertJsonPath('data.draft.payload.levels.3.attendance.0.student_id', '00001')->assertJsonPath('data.draft.payload.levels.3.attendance.0.group_code', 'G3');
        $draft = TeachingDraft::query()->findOrFail($id);
        $legacyPayload = $draft->payload;
        foreach ($legacyPayload['levels']['3']['attendance'] as $index => &$row) {
            $row['present'] = $index < 7 ? '1' : '0';
            $row['group_code'] = ' G3 ';
            $row['learning_method'] = $index < 7 ? '  ' : 'ออนไลน์';
        }
        unset($row);
        $draft->update(['payload' => $legacyPayload]);
        $this->getJson(route('teaching-drafts.index'))->assertOk()->assertJsonPath('data.drafts.0.progress.completed_levels', 1);
        $key = (string) Str::uuid();
        $request = ['lock_version' => 1, 'idempotency_key' => $key, 'confirmed' => true];
        if ($stringImageKeys) {
            $originalIds = $source->images()->pluck('id')->all();
            $this->mock(RecordRevisionService::class)->shouldReceive('capture')->once()->andThrow(new \RuntimeException('fixture revision failure after four photo inserts'));
            $this->postJson(route('teaching-drafts.finalize', $id), $request)->assertInternalServerError();
            $this->assertSame($originalIds, $source->images()->pluck('id')->all());
            $this->assertCount(4, Storage::disk('public')->allFiles());
            $this->assertDatabaseHas('teaching_drafts', ['id' => $id, 'status' => 'active', 'lock_version' => 1]);
            $this->assertDatabaseCount('weekly_submissions', 0);
            $this->app->forgetInstance(RecordRevisionService::class);
        }
        $result = $this->postJson(route('teaching-drafts.finalize', $id), $request)->assertOk();
        $result->assertJsonPath('data.record_ids', [$source->id]);
        $saved = $source->fresh();
        $this->assertNotNull($saved->weekly_submission_id);
        $this->assertSame($teacher->id, $saved->created_by);
        $this->assertSame(26, $saved->total);
        $this->assertSame(7, $saved->present);
        $this->assertSame(26, $saved->attendance()->count());
        $this->assertSame(4, $saved->images()->count());
        $this->assertSame(7, $saved->attendance()->where('present', true)->where('learning_method', 'พบกลุ่ม')->count());
        $this->assertSame(19, $saved->attendance()->where('present', false)->whereNull('learning_method')->count());
        $map = json_decode($saved->documentLayout->settings['image_layout_by_id'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing($saved->images()->pluck('id')->all(), array_map('strval', array_keys($map)));
        $this->assertEqualsCanonicalizing(['270', '290', '310', '330'], array_column($map, 'width_px'));
        foreach ($saved->images as $image) {
            $this->assertNotEmpty($image->id);
            $this->assertTrue($stringImageKeys ? str_starts_with($image->id, 'i') && strlen($image->id) === 20 : ctype_digit($image->id));
        }
        $this->postJson(route('teaching-drafts.finalize', $id), $request)->assertOk()->assertJsonPath('data.record_ids', [$source->id]);
        $this->assertDatabaseCount('records', 1);
    }

    public static function imageKeySchemas(): array
    {
        return ['integer auto increment' => [false], 'legacy string primary key' => [true]];
    }

    public function test_failed_document_validation_rolls_back_all_levels_and_leaves_the_draft_editable(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['levels']['3']['document']['settings'] = ['paper_orientation' => 'invalid'];
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])
            ->assertCreated()->json('data.draft.id');

        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_revisions', 0);
        $this->assertDatabaseCount('weekly_submissions', 0);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'status' => 'active', 'lock_version' => 1]);
    }

    public function test_private_images_and_mutations_are_not_accessible_to_another_teacher(): void
    {
        Storage::fake('local');
        $owner = $this->createTeacher('u1', 't1');
        $other = $this->createTeacher('u2', 't2');
        $draftId = $this->actingAs($owner)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $imageId = $this->post(route('teaching-drafts.images.store', $draftId), [
            'lock_version' => 1, 'level_code' => '1', 'image' => UploadedFile::fake()->image('one.jpg', 300, 300),
        ])->assertCreated()->json('data.image.id');

        $this->actingAs($other)->get(route('teaching-drafts.images.show', [$draftId, $imageId]))->assertNotFound();
        $this->deleteJson(route('teaching-drafts.images.destroy', [$draftId, $imageId]), ['lock_version' => 2])->assertNotFound();
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 2, 'payload' => $this->draftPayload()])->assertNotFound();

        $this->assertDatabaseCount('draft_images', 1);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draftId, 'lock_version' => 2]);
    }

    public function test_image_layout_follows_image_identity_through_finalize_source_import_and_reorder(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->createTeacher('u1', 't1');
        $draftId = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $imageIds = [];
        foreach ([1, 2] as $position) {
            $imageIds[] = $this->post(route('teaching-drafts.images.store', $draftId), [
                'lock_version' => $position, 'level_code' => '1', 'image' => UploadedFile::fake()->image('photo'.$position.'.jpg', 300, 300),
            ])->assertCreated()->json('data.image.id');
        }
        $layouts = json_encode([
            $imageIds[0] => ['width_px' => '410', 'crop_x' => '35'],
            $imageIds[1] => ['width_px' => '250', 'crop_x' => '65'],
        ], JSON_THROW_ON_ERROR);
        $this->putJson(route('teaching-drafts.document.update', [$draftId, '1']), [
            'lock_version' => 3, 'settings' => ['image_layout_by_id' => $layouts],
            'content' => ['record_subject' => 'ภาษาไทย', 'record_result' => 'เรียนรู้ตามแผน'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 4, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $recordId = $confirmed->json('data.record_ids.0');
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $recordId])->assertCreated();
        $editDraftId = $editing->json('data.draft.id');
        $importedIds = $editing->json('data.draft.payload.levels.1.image_ids');
        $this->patchJson(route('teaching-drafts.images.reorder', $editDraftId), [
            'lock_version' => 1, 'level_code' => '1', 'image_ids' => array_reverse($importedIds),
        ])->assertOk();

        $this->postJson(route('teaching-drafts.finalize', $editDraftId), [
            'lock_version' => 2, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $settings = TeachingRecord::query()->findOrFail($recordId)->documentLayout->settings;
        $this->assertSame('250', $settings['image_1_width_px']);
        $this->assertSame('65', $settings['image_1_crop_x']);
        $this->assertSame('410', $settings['image_2_width_px']);
        $this->assertSame('35', $settings['image_2_crop_x']);
        $this->assertDatabaseCount('record_images', 2);
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    public function test_draft_rejects_week_greater_than_20(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['scope']['week'] = 21;

        $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.scope.week');
    }

    public function test_one_level_can_be_saved_at_week_20_and_other_levels_added_without_overwriting(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['scope']['week'] = 20;
        $payload['enabled_level_codes'] = ['1'];
        $payload['levels']['2']['subject'] = '';
        $first = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated();
        $firstResult = $this->postJson(route('teaching-drafts.finalize', $first->json('data.draft.id')), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonCount(1, 'data.record_ids');
        $firstId = $firstResult->json('data.record_ids.0');
        $payload['enabled_level_codes'] = ['3'];
        $second = $this->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated();

        $this->postJson(route('teaching-drafts.finalize', $second->json('data.draft.id')), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonCount(1, 'data.record_ids');

        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseCount('records', 2);
        $this->assertDatabaseHas('records', ['id' => $firstId, 'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'week' => 20]);
        $this->assertDatabaseMissing('records', ['level' => 'มัธยมศึกษาตอนต้น']);
    }

    public function test_invalid_level_selections_are_rejected_and_disabled_preview_is_not_rendered(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        foreach ([[], ['1', '1'], ['4']] as $codes) {
            $payload['enabled_level_codes'] = $codes;
            $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertUnprocessable();
        }
        $this->assertDatabaseCount('teaching_drafts', 0);
        $payload['enabled_level_codes'] = ['1'];
        $id = $this->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldNotReceive('snapshotPdf');

        $this->postJson(route('teaching-drafts.preview', $id), ['lock_version' => 1, 'level_code' => '2'])
            ->assertUnprocessable()->assertJsonValidationErrors('level_code');
        $this->assertDatabaseCount('records', 0);
    }

    public function test_disabling_a_saved_source_level_cannot_delete_existing_records(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $id), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $confirmed->json('data.record_ids.0')])->assertCreated();
        $payload = $editing->json('data.draft.payload');
        $payload['enabled_level_codes'] = ['1'];
        $editId = $editing->json('data.draft.id');
        $this->patchJson(route('teaching-drafts.update', $editId), ['lock_version' => 1, 'payload' => $payload])->assertOk();

        $this->postJson(route('teaching-drafts.finalize', $editId), ['lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.enabled_level_codes');

        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseCount('record_revisions', 3);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $editId, 'status' => 'active']);
    }

    public function test_final_pdf_uses_reviewed_settings_even_if_global_settings_change(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        Setting::create(['setting_key' => 'pdf_margin_left_px', 'setting_value' => '94']);
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldReceive('snapshotPdf')->once()->andReturn('%PDF-reviewed');
        $this->postJson(route('teaching-drafts.preview', $id), ['lock_version' => 1, 'level_code' => '1'])->assertOk();
        Setting::where('setting_key', 'pdf_margin_left_px')->update(['setting_value' => '150']);

        $result = $this->postJson(route('teaching-drafts.finalize', $id), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'reviewed_level_codes' => ['1'],
        ])->assertOk();

        $record = TeachingRecord::findOrFail($result->json('data.record_ids.0'));
        $this->assertSame('94', $record->documentLayout->settings['pdf_margin_left_px']);
        $this->assertSame('free', $record->documentLayout->settings['image_layout_mode']);
        $revision = RecordRevision::where('record_id', $record->id)->firstOrFail();
        $this->assertSame('94', $revision->snapshot['settings']['pdf_margin_left_px']);
    }

    public function test_expired_review_cannot_be_confirmed_as_current_pdf(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        Cache::forget(DraftService::reviewCacheKey($id, 1, '1'));

        $this->postJson(route('teaching-drafts.finalize', $id), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'reviewed_level_codes' => ['1'],
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewed_level_codes');

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('weekly_submissions', 0);
    }

    public function test_unavailable_pdf_returns_json_for_workspace_and_does_not_approve_review(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldReceive('snapshotPdf')->once()->andThrow(new DocumentRuntimeUnavailableException);

        $this->postJson(route('teaching-drafts.preview', $id), ['lock_version' => 1, 'level_code' => '1'], ['Accept' => 'application/json, application/pdf'])
            ->assertServiceUnavailable()->assertJsonPath('code', 'document_renderer_unavailable')
            ->assertJsonPath('message', DocumentRuntimeUnavailableException::PUBLIC_MESSAGE);

        $this->assertNull(Cache::get(DraftService::reviewCacheKey($id, 1, '1')));
        $this->assertDatabaseHas('teaching_drafts', ['id' => $id, 'status' => 'active', 'lock_version' => 1]);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_explicit_browser_review_saves_all_levels_with_reviewed_settings_without_chrome(): void
    {
        Storage::fake('public');
        $user = $this->createTeacher('u1', 't1');
        Setting::create(['setting_key' => 'pdf_margin_left_px', 'setting_value' => '94']);
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldNotReceive('snapshotPdf');
        $print->shouldReceive('browserSnapshotHtml')->times(3)
            ->withArgs(fn (array $snapshot, User $viewer, string $back): bool => $viewer->is($user)
                && $snapshot['settings']['pdf_margin_left_px'] === '94'
                && str_contains($back, 'draft='.$id))
            ->andReturn('<!doctype html><html><body><section class="page">ตรวจเอกสาร</section></body></html>');

        foreach (['1', '2', '3'] as $code) {
            $this->postJson(route('teaching-drafts.preview', $id), ['lock_version' => 1, 'level_code' => $code, 'format' => 'browser'])
                ->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
                ->assertSee('ตรวจเอกสาร');
        }
        Setting::where('setting_key', 'pdf_margin_left_px')->update(['setting_value' => '150']);
        $this->postJson(route('teaching-drafts.finalize', $id), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'reviewed_level_codes' => ['1', '2', '3'],
        ])->assertOk()->assertJsonCount(3, 'data.record_ids');

        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseCount('record_revisions', 3);
        foreach (TeachingRecord::all() as $record) {
            $this->assertSame('94', $record->documentLayout->settings['pdf_margin_left_px']);
        }
    }

    public function test_browser_review_rejects_unowned_disabled_invalid_and_stale_requests(): void
    {
        $owner = $this->createTeacher('u1', 't1');
        $other = $this->createTeacher('u2', 't2');
        $payload = $this->draftPayload();
        $payload['enabled_level_codes'] = ['1'];
        $id = $this->actingAs($owner)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->json('data.draft.id');
        $url = route('teaching-drafts.preview', $id);
        $data = ['lock_version' => 1, 'level_code' => '1', 'format' => 'browser'];
        $this->mock(LegacyPrintService::class)->shouldNotReceive('browserSnapshotHtml');

        $this->actingAs($other)->postJson($url, $data)->assertNotFound();
        $this->actingAs($owner)->postJson($url, [...$data, 'level_code' => '2'])->assertUnprocessable();
        $this->postJson($url, [...$data, 'format' => 'invalid'])->assertUnprocessable();
        $this->patchJson(route('teaching-drafts.update', $id), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $this->postJson($url, $data)->assertConflict();
        $this->assertNull(Cache::get(DraftService::reviewCacheKey($id, 1, '1')));
        $this->assertDatabaseCount('records', 0);
    }

    public function test_failed_browser_render_never_approves_the_draft_and_unrelated_errors_stay_errors(): void
    {
        config()->set('app.debug', false);
        $user = $this->createTeacher('u1', 't1');
        $id = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()])->json('data.draft.id');
        $this->mock(LegacyPrintService::class)->shouldReceive('browserSnapshotHtml')->once()->andThrow(new \RuntimeException('HTML render failed'));

        $this->postJson(route('teaching-drafts.preview', $id), ['lock_version' => 1, 'level_code' => '1', 'format' => 'browser'])->assertInternalServerError();
        $this->assertNull(Cache::get(DraftService::reviewCacheKey($id, 1, '1')));
        $this->assertDatabaseHas('teaching_drafts', ['id' => $id, 'status' => 'active']);
    }

    private function createTeacher(string $id, string $tambon): User
    {
        Tambon::query()->firstOrCreate(['id' => $tambon], ['name' => 'ตำบล '.$tambon]);

        return User::query()->create([
            'id' => $id, 'name' => 'ครู '.$id, 'username' => 'teacher-'.$id,
            'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => $tambon,
        ]);
    }

    /** @return array<string, mixed> */
    private function draftPayload(string $tambon = 't1'): array
    {
        $levels = [];
        foreach (['1' => 'ภาษาไทย', '2' => 'คณิตศาสตร์', '3' => 'วิทยาศาสตร์'] as $code => $subject) {
            $levels[$code] = [
                'subject' => $subject,
                'subject_code' => 'SUB'.$code,
                'class_group_code' => 'G'.$code,
                'class_name' => 'กลุ่ม '.$code,
                'total' => 20,
                'present' => 18,
                'result' => 'เรียนรู้ตามแผน',
                'problem' => '',
                'suggestion' => '',
                'attendance_mode' => 'counts',
                'attendance_reviewed' => false,
                'attendance' => [],
                'image_ids' => [],
            ];
        }

        return [
            'schema_version' => 1,
            'scope' => ['tambon' => $tambon, 'week' => 10, 'date' => '2026-10-16', 'semester' => '1', 'year' => '2569'],
            'levels' => $levels,
            'active_level_code' => '1',
        ];
    }
}
