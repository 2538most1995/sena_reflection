<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use App\Services\RecordEditorTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoundDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private TeachingRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        Tambon::create(['id' => 't2', 'name' => 'สามกอ']);
        $this->teacher = $this->user('bound-teacher', 'tambon', 't1');
        $this->record = TeachingRecord::create(['id' => 'bound-record', 'tambon' => 't1', 'week' => 3, 'date' => '2026-10-23', 'year' => '2569', 'subject' => 'ภาษาไทย', 'result' => "ผลเดิม\nถัดไป", 'problem' => 'ปัญหาเดิม', 'suggestion' => 'ข้อเสนอเดิม', 'created_by' => $this->teacher->id]);
    }

    public function test_old_record_route_opens_one_bound_advanced_document_and_preserves_existing_imported_copies(): void
    {
        $copy = Document::factory()->create(['user_id' => $this->teacher->id, 'source_record_id' => $this->record->id, 'title' => 'สำเนาที่แก้แล้ว', 'revision' => 3]);
        $first = $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('documents/edit')->where('editorContext.kind', 'record')->where('editorContext.recordId', $this->record->id));
        $bound = Document::query()->where('binding_key', 'record:'.$this->record->id)->firstOrFail();
        $this->assertNotSame($copy->id, $bound->id);
        $this->get(route('records.document.edit', $this->record))->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.id', $bound->id));
        $this->assertSame('สำเนาที่แก้แล้ว', $copy->fresh()->title);
        $this->assertSame(3, $copy->fresh()->revision);
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_bound_save_updates_canonical_fields_settings_and_rich_print_projection_in_one_transaction(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $content = $document->content_json;
        $this->replaceField($content, 'record_result', "ผลใหม่\nบรรทัดสอง", [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => '#ff0000']]]);
        $this->replaceField($content, 'sig1_name', '( ครูทดสอบใหม่ )');
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content, 'pageSettings' => ['margins' => ['left' => 25]]])->assertOk()
            ->assertJsonPath('document.editorContext.kind', 'record')->assertJsonPath('document.revision', 2);
        $record = $this->record->fresh();
        $this->assertSame("ผลใหม่\nบรรทัดสอง", $record->result);
        $settings = $record->documentLayout->settings;
        $this->assertSame('ครูทดสอบใหม่', $settings['sig1_name']);
        $this->assertSame('94', $settings['pdf_margin_left_px']);
        $rich = json_decode($settings['document_rich_text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('<b>', $rich['record_result']['html']);
        $this->assertStringContainsString('#ff0000', $rich['record_result']['html']);
        $this->assertSame('ครูทดสอบใหม่', $rich['sig1_name']['text']);
        $this->assertStringNotContainsString('(', $rich['sig1_name']['html']);
        $snapshot = app(LegacyPrintService::class)->savedSnapshot($record, $this->teacher);
        $this->assertSame($record->result, $snapshot['content']['record_result']);
        $this->get(route('records.document.edit', $record))->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.id', $document->id)->where('document.revision', 2));
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'title' => 'stale'])->assertConflict();
    }

    public function test_bound_record_permissions_follow_record_scope_and_external_record_edits_conflict(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $same = $this->user('bound-same', 'tambon', 't1');
        $other = $this->user('bound-other', 'tambon', 't2');
        $this->actingAs($same)->getJson('/api/documents/'.$document->id)->assertOk();
        $this->actingAs($other)->getJson('/api/documents/'.$document->id)->assertForbidden();
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'title' => 'forbidden'])->assertForbidden();
        $this->record->update(['problem' => 'เปลี่ยนจากหน้าอื่น']);
        $this->actingAs($this->teacher)->putJson('/api/documents/'.$document->id, ['revision' => 1, 'title' => 'conflict'])->assertConflict()->assertJsonPath('code', 'source_record_conflict');
        $this->assertSame(1, $document->fresh()->revision);
    }

    public function test_thai_distributed_bound_fields_keep_semantics_in_json_rich_projection_and_source_reimport(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $content = $document->content_json;
        $markAlignment = function (array &$node) use (&$markAlignment): void {
            if (($node['type'] ?? '') === 'recordBody') {
                $node['attrs']['align'] = 'thai-distributed';
            }
            if (($node['attrs']['field'] ?? null) === 'record_result') {
                $node['content'][0]['attrs']['textAlign'] = 'thai-distributed';
            }
            if (($node['attrs']['field'] ?? null) === 'record_subject') {
                $node['attrs']['textAlign'] = 'thai-distributed';
            }
            if (isset($node['content'])) {
                foreach ($node['content'] as &$child) {
                    $markAlignment($child);
                }
                unset($child);
            }
        };
        $markAlignment($content);
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content])->assertOk()->assertJsonPath('document.content', $content);
        $settings = $this->record->fresh()->documentLayout->settings;
        $this->assertSame('thai-distributed', $settings['record_text_align']);
        $rich = json_decode($settings['document_rich_text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('text-align:justify;text-align-last:left;text-justify:inter-character', $rich['record_result']['html']);
        $this->assertStringContainsString('text-justify:inter-character', $rich['record_subject']['html']);
        $snapshot = app(LegacyPrintService::class)->savedSnapshot($this->record->fresh(), $this->teacher);
        $snapshot['settings']['record_text_align'] = 'justify';
        $rebuilt = app(RecordEditorTemplate::class)->build($snapshot);
        $this->assertSame('thai-distributed', $this->fieldNode($rebuilt['content'], 'record_result')['attrs']['align']);
        $this->assertSame('thai-distributed', $this->fieldNode($rebuilt['content'], 'record_subject')['attrs']['textAlign']);
        $this->record->refresh()->update(['problem' => 'เปลี่ยนข้อมูลจากหน้าอื่น']);
        $saved = $this->getJson('/api/documents/'.$document->id)->assertOk()->json('document.content');
        $this->assertSame('thai-distributed', $this->fieldNode($saved, 'record_result')['content'][0]['attrs']['textAlign']);
        $this->assertSame('thai-distributed', $this->fieldNode($saved, 'record_result')['attrs']['align']);
    }

    public function test_draft_bound_save_remains_a_private_active_draft_and_tracks_the_new_lock_version(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->teacher)->get(route('teaching-drafts.document.edit', [$draft->id, '1']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('documents/edit')->where('editorContext.kind', 'draft')->where('editorContext.lockVersion', 1));
        $document = Document::query()->where('binding_key', 'draft:'.$draft->id.':1')->firstOrFail();
        $content = $document->content_json;
        $this->replaceField($content, 'record_result', 'แก้ในฉบับร่าง');
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content])->assertOk()->assertJsonPath('document.editorContext.lockVersion', 2);
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame('แก้ในฉบับร่าง', $draft->fresh()->payload['levels']['1']['result']);
        $this->assertSame(2, $draft->fresh()->lock_version);
        $this->assertDatabaseCount('records', 1);
        $district = $this->user('bound-district', 'district', null);
        $this->actingAs($district)->getJson('/api/documents/'.$document->id)->assertNotFound();
        $this->actingAs($this->teacher);
        $draft->increment('lock_version');
        $this->putJson('/api/documents/'.$document->id, ['revision' => 2, 'title' => 'stale draft'])->assertConflict();
        $this->assertSame(2, $document->fresh()->revision);
    }

    public function test_loading_latest_rebases_changed_source_fields_preserves_format_and_archives_prior_advanced_content(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $content = $document->content_json;
        $problemParagraphId = $this->fieldNode($content, 'record_problem')['content'][0]['attrs']['id'];
        $this->replaceField($content, 'record_result', 'ผลที่จัดรูปแบบ', [['type' => 'bold']]);
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content])->assertOk();
        $frozenRevision = RecordRevision::query()->where('record_id', $this->record->id)->latest('revision')->firstOrFail();
        $frozen = $frozenRevision->snapshot;
        $this->assertSame(2, $frozen['advanced_document']['revision']);
        $this->record->refresh()->update(['problem' => 'ปัญหาจากหน้าบันทึก']);
        $this->putJson('/api/documents/'.$document->id, ['revision' => 2, 'title' => 'stale'])->assertConflict();
        $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->assertJsonPath('document.revision', 3)->json('document');
        $result = $this->fieldNode($latest['content'], 'record_result');
        $problem = $this->fieldNode($latest['content'], 'record_problem');
        $this->assertStringContainsString('ผลที่จัดรูปแบบ', json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('bold', json_encode($result));
        $this->assertStringContainsString('ปัญหาจากหน้าบันทึก', json_encode($problem, JSON_UNESCAPED_UNICODE));
        $this->assertSame($problemParagraphId, $problem['content'][0]['attrs']['id']);
        $this->assertSame(3, $document->fresh()->versions()->count());
        $this->assertSame($frozen, $frozenRevision->fresh()->snapshot);
        $this->assertSame(3, RecordRevision::query()->where('record_id', $this->record->id)->latest('revision')->firstOrFail()->snapshot['advanced_document']['revision']);
        $this->putJson('/api/documents/'.$document->id, ['revision' => 3, 'title' => 'ฉบับล่าสุด'])->assertOk();
        $this->assertSame('ปัญหาจากหน้าบันทึก', $this->record->fresh()->problem);
    }

    public function test_loading_latest_recovers_a_draft_source_conflict_without_finalizing(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->teacher)->get(route('teaching-drafts.document.edit', [$draft->id, '1']))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $payload = $draft->payload;
        $payload['levels']['1']['result'] = 'แก้จากหน้าร่างหลัก';
        $this->patchJson(route('teaching-drafts.update', $draft), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->assertJsonPath('document.revision', 2)->assertJsonPath('document.editorContext.lockVersion', 2)->json('document');
        $this->assertStringContainsString('แก้จากหน้าร่างหลัก', json_encode($this->fieldNode($latest['content'], 'record_result'), JSON_UNESCAPED_UNICODE));
        $this->putJson('/api/documents/'.$document->id, ['revision' => 2, 'title' => 'ต่อจากฉบับล่าสุด'])->assertOk();
        $this->assertSame('active', $draft->fresh()->status);
    }

    public function test_finalize_transfers_the_bound_draft_document_and_rejects_stale_editor_saves(): void
    {
        $draft = $this->draft();
        $payload = $draft->payload;
        $payload['levels']['1']['class_name'] = 'กลุ่มเสนา';
        $this->patchJson(route('teaching-drafts.update', $draft), ['lock_version' => 1, 'payload' => $payload])->assertOk();
        $this->actingAs($this->teacher)->get(route('teaching-drafts.document.edit', [$draft->id, '1']))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $content = $document->content_json;
        $this->replaceField($content, 'record_result', 'ผลจากเอกสารที่ยืนยัน', [['type' => 'bold']]);
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content])->assertOk();
        $recordId = $this->postJson(route('teaching-drafts.finalize', $draft), ['lock_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertOk()->json('data.record_ids.0');
        $this->assertSame('finalized', $draft->fresh()->status);
        $this->assertSame('record:'.$recordId, $document->fresh()->binding_key);
        $this->assertSame(3, $document->fresh()->revision);
        $this->get(route('records.document.edit', $recordId))->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.id', $document->id)->where('editorContext.kind', 'record'));
        $this->putJson('/api/documents/'.$document->id, ['revision' => 2, 'title' => 'old draft editor'])->assertConflict();
        $this->assertSame('ผลจากเอกสารที่ยืนยัน', TeachingRecord::query()->findOrFail($recordId)->result);
        $snapshot = RecordRevision::query()->where('record_id', $recordId)->firstOrFail()->snapshot;
        $this->assertSame($document->id, $snapshot['advanced_document']['id']);
        $this->assertSame(3, $snapshot['advanced_document']['revision']);
    }

    public function test_library_scope_hides_private_drafts_and_bound_delete_preserves_canonical_history(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $recordDocument = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $draft = $this->draft();
        $this->get(route('teaching-drafts.document.edit', [$draft->id, '1']))->assertOk();
        $this->deleteJson('/api/documents/'.$recordDocument->id)->assertUnprocessable()->assertJsonValidationErrors('document');
        $same = $this->user('bound-library-same', 'tambon', 't1');
        $this->actingAs($same)->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.id', $recordDocument->id)->assertJsonPath('documents.0.isBound', true);
        $district = $this->user('bound-library-dist', 'district', null);
        $this->actingAs($district)->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'documents');
        $this->assertDatabaseHas('documents', ['id' => $recordDocument->id]);
    }

    public function test_source_photo_roster_and_metadata_rebase_preserves_user_layout_and_frozen_print_assets(): void
    {
        $first = $this->sourceImage('first.png');
        $removed = $this->sourceImage('removed.png');
        $this->attendance('student-a', 'ผู้เรียนเดิม', true);
        $this->record->update(['total' => 2, 'present' => 1, 'level' => 'ประถมศึกษา', 'semester' => '1']);
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $managed = $document->binding_snapshot['managed'];
        $firstId = $managed['images'][(string) $first->id]['object_id'];
        $removedId = $managed['images'][(string) $removed->id]['object_id'];
        $removedAssetId = $managed['images'][(string) $removed->id]['asset_id'];
        $objects = $document->objects_json;
        foreach ($objects as &$object) {
            if ($object['id'] === $firstId) {
                $object['x'] = 37.25;
                $object['width'] = 91.5;
                $object['rotation'] = 15;
            }
        }
        unset($object);
        $custom = ['id' => 'custom-shape', 'type' => 'shape', 'shape' => 'rect', 'page' => 2, 'x' => 170, 'y' => 250, 'width' => 15, 'height' => 15, 'rotation' => 0, 'zIndex' => 9, 'opacity' => 1, 'wrap' => 'square', 'anchor' => ['kind' => 'page', 'page' => 2], 'fill' => '#00ff00', 'border' => '#000000', 'borderWidth' => 1, 'lockAspectRatio' => false];
        $objects[] = $custom;
        $content = $document->content_json;
        foreach ($content['content'] as &$node) {
            if (($node['type'] ?? '') === 'table') {
                $node['attrs']['columnSize'] = '10,20,30,20,20';
                $node['content'][1]['content'][2]['attrs']['backgroundColor'] = '#ffff00';
            }
        }
        unset($node);
        $customTable = ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'user-note'], 'content' => [['type' => 'text', 'text' => 'ตารางเพิ่มเติมของผู้ใช้']]]]]]]]];
        $content['content'][] = $customTable;
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'content' => $content, 'objects' => $objects])->assertOk();
        $prior = RecordRevision::query()->where('record_id', $this->record->id)->latest('revision')->firstOrFail();
        $frozen = $prior->snapshot;
        $oldVersion = $document->versions()->where('revision', 2)->firstOrFail()->snapshot_json;
        $removed->delete();
        $added = $this->sourceImage('added.png');
        $this->record->attendance()->delete();
        $this->attendance('student-a', 'ผู้เรียนที่แก้ชื่อ', true, 'ออนไลน์');
        $this->attendance('student-c', 'ผู้เรียนที่เพิ่มใหม่', true);
        $this->record->update(['week' => 5, 'date' => '2026-10-30', 'semester' => '2', 'year' => '2570', 'level' => 'มัธยมศึกษาตอนต้น', 'total' => 4, 'present' => 2]);
        $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->assertJsonPath('document.revision', 3)->json('document');
        $this->assertCount(3, $latest['objects']);
        $kept = collect($latest['objects'])->firstWhere('id', $firstId);
        $this->assertSame(37.25, $kept['x']);
        $this->assertSame(91.5, $kept['width']);
        $this->assertEquals(15, $kept['rotation']);
        $this->assertNull(collect($latest['objects'])->firstWhere('id', $removedId));
        $this->assertSame($custom, collect($latest['objects'])->firstWhere('id', 'custom-shape'));
        $map = $document->fresh()->binding_snapshot['managed'];
        $this->assertArrayHasKey((string) $added->id, $map['images']);
        $new = collect($latest['objects'])->firstWhere('id', $map['images'][(string) $added->id]['object_id']);
        $this->assertSame($managed['photo']['heading_id'], $new['anchor']['id']);
        $this->assertTrue($this->containsNodeId($latest['content'], $new['anchor']['id']));
        $this->get('/api/documents/'.$document->id.'/assets/'.$removedAssetId)->assertOk();
        $this->assertSame($frozen, $prior->fresh()->snapshot);
        $this->assertSame($oldVersion, $document->versions()->where('revision', 2)->firstOrFail()->snapshot_json);
        $table = collect($latest['content']['content'])->first(fn (array $node): bool => ($node['type'] ?? '') === 'table' && $this->containsNodeId($node, $map['attendance']['table_marker']));
        $this->assertSame('10,20,30,20,20', $table['attrs']['columnSize']);
        $this->assertSame('#ffff00', $table['content'][1]['content'][2]['attrs']['backgroundColor']);
        $text = json_encode($table, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ผู้เรียนที่แก้ชื่อ', $text);
        $this->assertStringContainsString('ผู้เรียนที่เพิ่มใหม่', $text);
        $this->assertStringContainsString('ออนไลน์', $text);
        $this->assertSame($customTable, collect($latest['content']['content'])->first(fn (array $node): bool => $this->containsNodeId($node, 'user-note')));
        $meta = collect($latest['content']['content'])->filter(static fn (array $node): bool => ($node['type'] ?? '') === 'recordInfoRow');
        $metaText = json_encode($meta, JSON_UNESCAPED_UNICODE);
        foreach (['2570', 'มัธยมศึกษาตอนต้น', '"text":"5"', '"text":"30"', '"text":"4"', '"text":"2"'] as $expected) {
            $this->assertStringContainsString($expected, $metaText);
        }
        $print = app(LegacyPrintService::class)->savedSnapshot($this->record->fresh(), $this->teacher);
        $this->assertSame($latest['objects'], $print['advanced_document']['objects']);
        $this->assertSame($latest['content'], $print['advanced_document']['content']);
        $this->assertSame(3, RecordRevision::query()->where('record_id', $this->record->id)->latest('revision')->firstOrFail()->snapshot['advanced_document']['revision']);
    }

    public function test_first_source_photo_is_inserted_before_existing_attendance_and_missing_new_files_never_advance_the_binding(): void
    {
        $this->attendance('student-a', 'ผู้เรียน', true);
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $attendanceHeading = $document->binding_snapshot['managed']['attendance']['heading_id'];
        $this->sourceImage('first-after-seed.png');
        $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->json('document');
        $this->assertCount(1, $latest['objects']);
        $headingId = $latest['objects'][0]['anchor']['id'];
        $ids = array_column(array_column($latest['content']['content'], 'attrs'), 'id');
        $this->assertLessThan(array_search($attendanceHeading, $ids), array_search($headingId, $ids));
        $revision = $document->fresh()->revision;
        $binding = $document->binding_snapshot;
        $paths = Storage::disk('local')->allFiles('documents/'.$document->id);
        $this->sourceImage('new-valid-before-failure.png');
        RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'storage/records/missing.png']);
        $this->getJson('/api/documents/'.$document->id)->assertConflict()->assertJsonPath('code', 'source_record_conflict');
        $this->assertSame($revision, $document->fresh()->revision);
        $this->assertSame($binding, $document->binding_snapshot);
        $this->assertEqualsCanonicalizing($paths, Storage::disk('local')->allFiles('documents/'.$document->id));
    }

    public function test_binding_without_managed_map_recovers_source_image_order_from_the_initial_version(): void
    {
        $first = $this->sourceImage('original-a.png');
        $this->sourceImage('original-b.png');
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk();
        $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
        $firstObject = $document->objects_json[0];
        $objects = $document->objects_json;
        $objects[0]['x'] = 48.5;
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'objects' => $objects])->assertOk();
        $binding = $document->fresh()->binding_snapshot;
        unset($binding['managed']);
        $document->update(['binding_snapshot' => $binding]);
        $this->sourceImage('new-after-old-binding.png');
        $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->json('document');
        $this->assertCount(3, $latest['objects']);
        $this->assertSame(48.5, collect($latest['objects'])->firstWhere('id', $firstObject['id'])['x']);
        $this->assertSame($firstObject['assetId'], $document->fresh()->binding_snapshot['managed']['images'][(string) $first->id]['asset_id']);
        $this->assertSame(3, $document->assets()->count());
    }

    public function test_finalized_draft_image_map_tracks_record_image_ids_and_preserves_layout_after_later_source_addition(): void
    {
        $previousDisk = Storage::disk('local');
        $fixtureRoot = storage_path('app/private/teaching-drafts/bound-finalization-'.Str::uuid());
        Storage::set('local', Storage::build(['driver' => 'local', 'root' => $fixtureRoot, 'throw' => true]));
        try {
            $draft = $this->draft();
            $payload = $draft->payload;
            $payload['levels']['1']['class_name'] = 'กลุ่มเสนา';
            $this->patchJson(route('teaching-drafts.update', $draft), ['lock_version' => 1, 'payload' => $payload])->assertOk();
            foreach (['draft-one.png', 'draft-two.png'] as $index => $name) {
                $this->post(route('teaching-drafts.images.store', $draft), ['lock_version' => 2 + $index, 'level_code' => '1', 'image' => UploadedFile::fake()->image($name, 300, 200)])->assertCreated();
            }
            $this->get(route('teaching-drafts.document.edit', [$draft->id, '1']))->assertOk();
            $document = Document::query()->whereNotNull('binding_key')->firstOrFail();
            $objects = $document->objects_json;
            $objects[0]['x'] = 62.5;
            $assetId = $objects[0]['assetId'];
            $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'objects' => $objects])->assertOk();
            $recordId = $this->postJson(route('teaching-drafts.finalize', $draft), ['lock_version' => 5, 'idempotency_key' => (string) Str::uuid()])->assertOk()->json('data.record_ids.0');
            $record = TeachingRecord::query()->findOrFail($recordId);
            $recordImage = $record->images()->orderBy('id')->firstOrFail();
            $this->assertSame($assetId, $document->fresh()->binding_snapshot['managed']['images'][(string) $recordImage->id]['asset_id']);
            $this->sourceImage('added-after-finalization.png', $record);
            $latest = $this->getJson('/api/documents/'.$document->id)->assertOk()->json('document');
            $this->assertCount(3, $latest['objects']);
            $this->assertSame(62.5, collect($latest['objects'])->firstWhere('assetId', $assetId)['x']);
            $this->assertSame(3, $document->assets()->count());
        } finally {
            File::deleteDirectory($fixtureRoot);
            Storage::set('local', $previousDisk);
        }
    }

    private function sourceImage(string $name, ?TeachingRecord $record = null): RecordImage
    {
        $path = 'records/'.$name;
        $image = UploadedFile::fake()->image($name, 300, 200);
        Storage::disk('public')->put($path, file_get_contents($image->getRealPath()));

        return RecordImage::create(['record_id' => ($record ?? $this->record)->id, 'file_path' => 'storage/'.$path]);
    }

    private function attendance(string $id, string $name, bool $present, string $method = 'พบกลุ่ม'): void
    {
        RecordAttendance::create(['record_id' => $this->record->id, 'student_id' => $id, 'full_name' => $name, 'present' => $present, 'learning_method' => $present ? $method : null]);
    }

    private function containsNodeId(array $node, string $id): bool
    {
        if (($node['attrs']['id'] ?? '') === $id) {
            return true;
        }
        foreach ($node['content'] ?? [] as $child) {
            if ($this->containsNodeId($child, $id)) {
                return true;
            }
        }

        return false;
    }

    private function fieldNode(array $node, string $field): ?array
    {
        if (($node['attrs']['field'] ?? null) === $field) {
            return $node;
        }
        foreach ($node['content'] ?? [] as $child) {
            if ($found = $this->fieldNode($child, $field)) {
                return $found;
            }
        }

        return null;
    }

    private function user(string $id, string $role, ?string $tambon): User
    {
        return User::create(['id' => $id, 'name' => $id, 'username' => $id, 'password' => Hash::make('test-password'), 'role' => $role, 'tambon' => $tambon]);
    }

    private function draft(): TeachingDraft
    {
        $id = $this->actingAs($this->teacher)->postJson(route('teaching-drafts.store'), ['payload' => ['scope' => ['tambon' => 't1', 'week' => 4, 'date' => '2026-10-23', 'semester' => '1', 'year' => '2569'], 'enabled_level_codes' => ['1'], 'levels' => ['1' => ['subject' => 'ภาษาไทย', 'result' => 'ร่างเดิม', 'problem' => 'ปัญหา', 'suggestion' => 'ข้อเสนอ', 'attendance_mode' => 'counts', 'total' => 2, 'present' => 1]]]])->assertCreated()->json('data.draft.id');

        return TeachingDraft::query()->findOrFail($id);
    }

    private function replaceField(array &$node, string $field, string $text, array $marks = []): void
    {
        if (($node['attrs']['field'] ?? null) === $field) {
            $node['content'] = array_map(static fn (string $line): array => ['type' => 'text', 'text' => $line, ...($marks === [] ? [] : ['marks' => $marks])], explode("\n", $text));
            if (($node['type'] ?? '') === 'recordBody') {
                $items = [];
                foreach (explode("\n", $text) as $index => $line) {
                    if ($index > 0) {
                        $items[] = ['type' => 'hardBreak'];
                    }
                    $items[] = ['type' => 'text', 'text' => $line, ...($marks === [] ? [] : ['marks' => $marks])];
                }
                $node['content'] = [['type' => 'paragraph', 'content' => $items]];
            }

            return;
        }
        if (isset($node['content'])) {
            foreach ($node['content'] as &$child) {
                $this->replaceField($child, $field, $text, $marks);
            }
        }
    }
}
