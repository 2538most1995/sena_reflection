<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DraftDocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_teacher_edits_text_and_layout_in_draft_without_publishing_records(): void
    {
        [$teacher, $draft] = $this->draft();
        $route = ['teachingDraft' => $draft->id, 'levelCode' => '1'];
        $this->actingAs($teacher)->get(route('teaching-drafts.document.edit', $route))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('records/document')
            ->where('draftContext.id', $draft->id)->where('draftContext.lockVersion', 1)
            ->where('content.record_subject', 'ภาษาไทย'));

        $this->put(route('teaching-drafts.document.update', $route), [
            'lock_version' => 1, 'settings' => ['paper_orientation' => 'landscape', 'image_1_width_px' => '410'],
            'content' => ['record_subject' => 'ภาษาไทย', 'record_result' => 'ผลที่ตรวจแก้ก่อนยืนยัน'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $saved = $draft->fresh();
        $this->assertSame(2, $saved->lock_version);
        $this->assertSame('ผลที่ตรวจแก้ก่อนยืนยัน', $saved->payload['levels']['1']['result']);
        $this->assertSame('landscape', $saved->payload['levels']['1']['document']['settings']['paper_orientation']);
        $this->assertSame('410', $saved->payload['levels']['1']['document']['settings']['image_1_width_px']);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_unsaved_preview_renders_submitted_text_without_changing_the_draft(): void
    {
        [$teacher, $draft] = $this->draft();
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->withArgs(fn (array $snapshot, User $user): bool => $snapshot['content']['record_result'] === 'ตัวอย่างที่ยังไม่บันทึก'
            && $snapshot['settings']['image_1_width_px'] === '470'
            && $user->is($teacher))->andReturn('%PDF-preview');

        $this->actingAs($teacher)->postJson(route('teaching-drafts.document.pdf-preview', [$draft->id, '1']), [
            'lock_version' => 1, 'settings' => ['image_1_width_px' => '470'],
            'content' => ['record_result' => 'ตัวอย่างที่ยังไม่บันทึก'],
        ])->assertOk()->assertHeader('content-type', 'application/pdf')->assertContent('%PDF-preview');

        $this->assertSame(1, $draft->fresh()->lock_version);
        $this->assertSame('ผลเดิม', $draft->fresh()->payload['levels']['1']['result']);
    }

    public function test_private_editor_rejects_other_teachers_guests_and_invalid_levels(): void
    {
        [$teacher, $draft] = $this->draft();
        $url = route('teaching-drafts.document.edit', [$draft->id, '1']);
        $this->get($url)->assertRedirect(route('login'));
        $other = User::create(['id' => 'other', 'username' => 'other', 'name' => 'ครูอื่น', 'password' => Hash::make('secret'), 'role' => 'district']);

        $this->actingAs($other)->get($url)->assertNotFound();
        $this->putJson(route('teaching-drafts.document.update', [$draft->id, '1']), ['lock_version' => 1])->assertNotFound();
        $this->actingAs($teacher)->get(route('teaching-drafts.document.edit', [$draft->id, '4']))->assertNotFound();
        $this->assertSame(1, $draft->fresh()->lock_version);
    }

    public function test_stale_version_and_invalid_settings_never_overwrite_the_draft(): void
    {
        [$teacher, $draft] = $this->draft();
        $url = route('teaching-drafts.document.update', [$draft->id, '1']);
        $payload = ['lock_version' => 1, 'settings' => ['paper_orientation' => 'invalid'], 'content' => ['record_subject' => 'ภาษาไทย']];
        $this->actingAs($teacher)->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('paper_orientation');
        $payload['lock_version'] = 2;
        $payload['settings']['paper_orientation'] = 'portrait';
        $this->putJson($url, $payload)->assertConflict()->assertJsonPath('code', 'draft_version_conflict');
        $this->assertSame(1, $draft->fresh()->lock_version);
        $this->assertDatabaseCount('records', 0);
    }

    /** @return array{User, TeachingDraft} */
    private function draft(): array
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $user = User::create(['id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher', 'password' => Hash::make('secret'), 'role' => 'tambon', 'tambon' => 't1']);
        $response = $this->actingAs($user)->postJson(route('teaching-drafts.store'), ['payload' => [
            'scope' => ['tambon' => 't1', 'week' => 12, 'date' => '2026-10-17', 'year' => '2569', 'semester' => '1'],
            'levels' => ['1' => ['subject' => 'ภาษาไทย', 'class_group_code' => 'G1', 'result' => 'ผลเดิม', 'attendance_mode' => 'counts', 'total' => 3, 'present' => 2]],
        ]])->assertCreated();
        $this->app['auth']->forgetGuards();

        return [$user, TeachingDraft::findOrFail($response->json('data.draft.id'))];
    }
}
