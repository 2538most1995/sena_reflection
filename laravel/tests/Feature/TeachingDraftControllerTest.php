<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\RecordRevision;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\DraftService;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_editing_a_week_moves_all_three_levels_and_preserves_record_ids(): void
    {
        $user = $this->createTeacher('u1', 't1');
        $created = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => $this->draftPayload()]);
        $confirmed = $this->postJson(route('teaching-drafts.finalize', $created->json('data.draft.id')), [
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $ids = $confirmed->json('data.record_ids');
        $editing = $this->postJson(route('teaching-drafts.store'), ['source_record_id' => $ids[0]])->assertCreated();
        $draftId = $editing->json('data.draft.id');
        $payload = $editing->json('data.draft.payload');
        $payload['scope']['week'] = 11;
        $payload['levels']['1']['result'] = 'ผลการสอนฉบับปรับปรุง';
        $this->patchJson(route('teaching-drafts.update', $draftId), ['lock_version' => 1, 'payload' => $payload])->assertOk();

        $this->postJson(route('teaching-drafts.finalize', $draftId), [
            'lock_version' => 2, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.record_ids', $ids);

        $this->assertDatabaseCount('records', 3);
        $this->assertDatabaseCount('weekly_submissions', 1);
        $this->assertDatabaseHas('weekly_submissions', ['id' => $confirmed->json('data.submission_id'), 'week' => 11]);
        $this->assertSame(3, TeachingRecord::query()->where('week', 11)->count());
        $this->assertDatabaseHas('records', ['id' => $ids[0], 'result' => 'ผลการสอนฉบับปรับปรุง']);
        $this->assertDatabaseCount('record_revisions', 6);
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
