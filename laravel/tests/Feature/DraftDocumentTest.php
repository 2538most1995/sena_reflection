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
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('documents/edit')
            ->where('editorContext.draftId', $draft->id)->where('editorContext.lockVersion', 1)
            ->where('document.content', fn ($content): bool => str_contains(json_encode($content, JSON_UNESCAPED_UNICODE), 'ภาษาไทย')));

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

    public function test_rich_text_survives_private_draft_save_reopen_and_preview_without_publishing(): void
    {
        [$teacher, $draft] = $this->draft();
        $route = [$draft->id, '1'];
        $text = 'ผลการเรียนรู้ที่จัดรูปแบบ';
        $richText = json_encode(['record_result' => ['text' => $text, 'html' => '<b onclick="alert(1)">'.$text.'</b><script>alert(1)</script>']], JSON_THROW_ON_ERROR);

        $this->actingAs($teacher)->putJson(route('teaching-drafts.document.update', $route), [
            'lock_version' => 1, 'settings' => ['document_rich_text' => $richText], 'content' => ['record_result' => $text],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $saved = $draft->fresh();
        $json = $saved->payload['levels']['1']['document']['settings']['document_rich_text'];
        $this->assertSame('<b>'.$text.'</b>', json_decode($json, true, flags: JSON_THROW_ON_ERROR)['record_result']['html']);
        $this->assertSame($text, $saved->payload['levels']['1']['result']);
        $this->get(route('teaching-drafts.document.edit', $route))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.content', fn ($content): bool => str_contains(json_encode($content, JSON_UNESCAPED_UNICODE), $text) && str_contains(json_encode($content), 'bold')));
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotPdf')->once()->withArgs(fn (array $snapshot, User $user): bool => $snapshot['settings']['document_rich_text'] === $json
            && $snapshot['content']['record_result'] === $text && $user->is($teacher))->andReturn('%PDF-rich-draft');
        $this->postJson(route('teaching-drafts.document.pdf-preview', $route), [
            'lock_version' => 2, 'settings' => ['document_rich_text' => $json], 'content' => ['record_result' => $text],
        ])->assertOk()->assertContent('%PDF-rich-draft');
        $this->assertSame(2, $saved->fresh()->lock_version);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_private_editor_rejects_other_teachers_guests_and_invalid_levels(): void
    {
        [$teacher, $draft] = $this->draft();
        $url = route('teaching-drafts.document.edit', [$draft->id, '1']);
        $this->get($url)->assertRedirect(route('login'));
        $browserUrl = route('teaching-drafts.document.browser-preview', [$draft->id, '1']);
        $this->postJson($browserUrl, ['lock_version' => 1])->assertUnauthorized();
        $other = User::create(['id' => 'other', 'username' => 'other', 'name' => 'ครูอื่น', 'password' => Hash::make('secret'), 'role' => 'district']);

        $this->actingAs($other)->get($url)->assertNotFound();
        $this->postJson($browserUrl, ['lock_version' => 1])->assertNotFound();
        $this->putJson(route('teaching-drafts.document.update', [$draft->id, '1']), ['lock_version' => 1])->assertNotFound();
        $this->actingAs($teacher)->get(route('teaching-drafts.document.edit', [$draft->id, '4']))->assertNotFound();
        $this->postJson(route('teaching-drafts.document.browser-preview', [$draft->id, '4']), ['lock_version' => 1])->assertNotFound();
        $this->assertSame(1, $draft->fresh()->lock_version);
    }

    public function test_browser_preview_prints_the_current_validated_draft_without_publishing_or_saving(): void
    {
        [$teacher, $draft] = $this->draft();
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldNotReceive('snapshotPdf');
        $print->shouldReceive('snapshotHtml')->once()->withArgs(fn (array $snapshot, User $user, bool $editor, bool $browserAssets): bool => $snapshot['content']['record_result'] === 'ข้อความฉบับปัจจุบัน'
            && $snapshot['settings']['image_1_width_px'] === '470'
            && $user->is($teacher) && ! $editor && $browserAssets)
            ->andReturn('<!doctype html><html><head><base href="http://localhost/"></head><body><section class="page">current draft</section></body></html>');

        $url = route('teaching-drafts.document.browser-preview', [$draft->id, '1']);
        $payload = ['lock_version' => 1, 'settings' => ['image_1_width_px' => '470'], 'content' => ['record_result' => 'ข้อความฉบับปัจจุบัน']];
        $this->actingAs($teacher)->postJson($url, $payload)->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('current draft')->assertSee('browser-print-button')
            ->assertSee(route('records.create', ['draft' => $draft->id, 'review' => 1]));
        $payload['lock_version'] = 2;
        $this->postJson($url, $payload)->assertConflict()->assertJsonPath('code', 'draft_version_conflict');
        $payload['lock_version'] = 1;
        $payload['settings']['paper_orientation'] = 'invalid';
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('paper_orientation');
        $this->assertSame(1, $draft->fresh()->lock_version);
        $this->assertSame('ผลเดิม', $draft->fresh()->payload['levels']['1']['result']);
        $this->assertDatabaseCount('records', 0);
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
