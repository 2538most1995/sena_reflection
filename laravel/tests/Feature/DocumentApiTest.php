<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\RecordAttendance;
use App\Models\RecordDocumentLayout;
use App\Models\RecordImage;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\RecordDocumentOptions;
use App\Services\RecordRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        Tambon::create(['id' => 't2', 'name' => 'สามกอ']);
        $this->owner = $this->user('doc-owner', 'tambon', 't1');
    }

    public function test_owner_can_save_reopen_and_restore_a_json_document_without_losing_format_or_objects(): void
    {
        $initial = ['title' => 'รายงานภาษาไทย', 'content' => $this->content(), 'objects' => [$this->shape()], 'pageSettings' => ['orientation' => 'landscape', 'header' => 'หัวกระดาษ'], 'schemaVersion' => 1];
        $response = $this->actingAs($this->owner)->postJson('/api/documents', $initial)->assertCreated()
            ->assertJsonPath('document.content.content.0.content.0.text', 'ภาษาไทย')->assertJsonPath('document.revision', 1);
        $id = $response->json('document.id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.content', $initial['content'])
            ->assertJsonPath('document.objects.0.x', 24)->assertJsonPath('document.pageSettings.orientation', 'landscape');
        $this->get('/documents/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('documents/edit')->where('document.id', $id));
        $this->putJson('/api/documents/'.$id, ['revision' => 1, 'title' => 'รายงานแก้ไข', 'content' => $this->content('ข้อความใหม่')])->assertOk()->assertJsonPath('document.revision', 2);
        $this->putJson('/api/documents/'.$id, ['revision' => 1, 'title' => 'ข้อมูลเก่าห้ามทับ'])->assertConflict()
            ->assertJsonPath('code', 'document_version_conflict')->assertJsonPath('document.title', 'รายงานแก้ไข')->assertJsonPath('document.revision', 2);
        $versions = $this->getJson('/api/documents/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'versions')->json('versions');
        $first = collect($versions)->firstWhere('revision', 1);
        $this->postJson('/api/documents/'.$id.'/versions/'.$first['id'].'/restore', ['revision' => 2])->assertOk()
            ->assertJsonPath('document.title', $initial['title'])->assertJsonPath('document.content', $initial['content'])->assertJsonPath('document.revision', 3);
        $this->assertDatabaseCount('document_versions', 3);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'documents');
    }

    public function test_guest_other_owner_and_district_have_the_expected_document_permissions(): void
    {
        $document = Document::factory()->create(['user_id' => $this->owner->id]);
        $this->getJson('/api/documents')->assertUnauthorized();
        $this->postJson('/api/documents', [])->assertUnauthorized();
        $this->getJson('/api/documents/'.$document->id)->assertUnauthorized();
        $other = $this->user('doc-other', 'tambon', 't1');
        $this->actingAs($other)->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'documents');
        $this->getJson('/api/documents/'.$document->id)->assertForbidden();
        $this->get('/documents/'.$document->id.'/edit')->assertForbidden();
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'title' => 'ห้าม'])->assertForbidden();
        $this->deleteJson('/api/documents/'.$document->id)->assertForbidden();
        $this->getJson('/api/documents/'.$document->id.'/versions')->assertForbidden();
        $district = $this->user('doc-district', 'district', null);
        $this->actingAs($district)->getJson('/api/documents/'.$document->id)->assertOk();
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'documents');
        $this->putJson('/api/documents/'.$document->id, ['revision' => 1, 'title' => 'เขตแก้ไข'])->assertOk()->assertJsonPath('document.revision', 2);
    }

    public function test_upload_is_private_and_images_remain_available_after_save_reopen_and_version_restore(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/documents', [])->assertCreated()->json('document.id');
        $asset = $this->postJson('/api/documents/'.$id.'/assets', ['file' => UploadedFile::fake()->image('ภาพกิจกรรม.png', 180, 120)])
            ->assertCreated()->assertJsonPath('asset.mime', 'image/png')->json('asset');
        $this->get($asset['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $this->assertStringNotContainsString('/storage/', $asset['url']);
        $object = [...$this->shape(), 'type' => 'image', 'assetId' => $asset['id'], 'src' => $asset['url']];
        $inline = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['assetId' => $asset['id'], 'src' => $asset['url'], 'alt' => 'ภาพในบรรทัด', 'width' => 180, 'height' => 120]]]]]];
        $this->putJson('/api/documents/'.$id, ['revision' => 1, 'content' => $inline, 'objects' => [$object]])->assertOk()->assertJsonPath('document.revision', 2);
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.objects.0.src', $asset['url'])->assertJsonPath('document.assets.0.id', $asset['id']);
        $version = DocumentVersion::query()->where('document_id', $id)->where('revision', 2)->firstOrFail();
        $this->putJson('/api/documents/'.$id, ['revision' => 2, 'objects' => []])->assertOk();
        $this->postJson('/api/documents/'.$id.'/versions/'.$version->id.'/restore', ['revision' => 3])->assertOk()->assertJsonPath('document.objects.0.assetId', $asset['id']);
        $other = $this->user('doc-file-other', 'tambon', 't2');
        $this->actingAs($other)->get($asset['url'])->assertForbidden();
        $this->postJson('/api/documents/'.$id.'/assets', ['file' => UploadedFile::fake()->image('other.png')])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson('/api/documents/'.$id)->assertOk();
        $this->assertDatabaseCount('document_assets', 0);
        $this->assertDatabaseCount('document_versions', 0);
        Storage::disk('local')->assertMissing('documents/'.$id);
    }

    public function test_upload_rejects_svg_executable_content_and_large_files(): void
    {
        $document = Document::factory()->create(['user_id' => $this->owner->id]);
        $this->actingAs($this->owner);
        foreach ([UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->createWithContent('bad.png', '<?php echo "bad";'), UploadedFile::fake()->create('large.png', 5121, 'image/png')] as $file) {
            $this->postJson('/api/documents/'.$document->id.'/assets', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('document_assets', 0);
    }

    public function test_invalid_nodes_marks_urls_geometry_and_schema_are_rejected_without_overwriting(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/documents', [])->assertCreated()->json('document.id');
        $payloads = [
            ['content' => ['type' => 'doc', 'content' => [['type' => 'script', 'text' => 'alert(1)']]]],
            ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)']]]]],
            ['content' => ['type' => 'doc', 'content' => [['type' => 'table', 'content' => []]]]],
            ['content' => ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'invalid block']]]],
            ['content' => $this->content('bad', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]])],
            ['content' => $this->content('bad', [['type' => 'textStyle', 'attrs' => ['fontSize' => 'url(https://example.test)']]])],
            ['content' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'file:///private/secrets']]]]],
            ['objects' => [[...$this->shape(), 'x' => 'zoom(2)']]], ['objects' => [$this->shape(), $this->shape()]],
            ['objects' => [[...$this->shape(), 'anchor' => ['kind' => 'paragraph']]]],
            ['pageSettings' => ['margins' => ['left' => 100, 'right' => 100]]], ['schemaVersion' => 2],
        ];
        foreach ($payloads as $payload) {
            $this->putJson('/api/documents/'.$id, ['revision' => 1, ...$payload])->assertUnprocessable()->assertJsonStructure(['errors']);
        }
        $this->putJson('/api/documents/'.$id, ['title' => 'missing revision'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(1, Document::query()->findOrFail($id)->revision);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_assets_and_versions_cannot_be_substituted_from_another_document(): void
    {
        $first = $this->actingAs($this->owner)->postJson('/api/documents', [])->assertCreated()->json('document.id');
        $second = $this->postJson('/api/documents', [])->assertCreated()->json('document.id');
        $asset = $this->postJson('/api/documents/'.$second.'/assets', ['file' => UploadedFile::fake()->image('second.png')])->assertCreated()->json('asset');
        $this->get('/api/documents/'.$first.'/assets/'.$asset['id'])->assertNotFound();
        $this->putJson('/api/documents/'.$first, ['revision' => 1, 'objects' => [[...$this->shape(), 'type' => 'image', 'src' => $asset['url'], 'assetId' => $asset['id']]]])->assertUnprocessable();
        $version = DocumentVersion::query()->where('document_id', $second)->firstOrFail();
        $this->postJson('/api/documents/'.$first.'/versions/'.$version->id.'/restore', ['revision' => 1])->assertNotFound();
    }

    public function test_import_from_record_is_scoped_idempotent_and_copies_images_without_touching_the_source(): void
    {
        $record = TeachingRecord::create(['id' => 'doc-source-record', 'tambon' => 't1', 'week' => 4, 'date' => '2026-10-22', 'level' => 'ประถมศึกษา', 'semester' => '1', 'year' => '2569', 'subject' => 'ภาษาไทย', 'result' => 'ผลเดิม[break]หน้าต่อไป', 'problem' => 'ปัญหา', 'suggestion' => 'ข้อเสนอ', 'created_by' => $this->owner->id]);
        $file = UploadedFile::fake()->image('source.png', 200, 150);
        Storage::disk('public')->put('records/source.png', $file->get());
        RecordImage::create(['record_id' => $record->id, 'file_path' => 'storage/records/source.png']);
        $other = $this->user('source-other', 'tambon', 't2');
        $this->actingAs($other)->postJson('/api/documents/from-record/'.$record->id, [])->assertForbidden();
        $first = $this->actingAs($this->owner)->postJson('/api/documents/from-record/'.$record->id, [])->assertCreated()
            ->assertJsonPath('document.sourceRecordId', $record->id)->assertJsonCount(1, 'document.assets')->assertJsonCount(1, 'document.objects')->json('document');
        $this->postJson('/api/documents/from-record/'.$record->id, [])->assertCreated()->assertJsonPath('document.id', $first['id']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertSame('ผลเดิม[break]หน้าต่อไป', $record->fresh()->result);
        Storage::disk('public')->assertExists('records/source.png');
        $nodes = $first['content']['content'];
        $this->assertGreaterThanOrEqual(1, count(array_filter($nodes, static fn (array $node): bool => $node['type'] === 'pageBreak')));
        $this->assertSame(end($nodes)['attrs']['id'], $first['objects'][0]['anchor']['id']);
        $this->deleteJson('/api/documents/'.$first['id'])->assertOk();
        Storage::disk('public')->assertExists('records/source.png');
        $this->assertDatabaseHas('record_images', ['record_id' => $record->id]);
    }

    public function test_tables_textbox_paragraph_anchors_and_styled_text_are_round_tripped(): void
    {
        $cell = ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => [120], 'align' => null], 'content' => $this->content('ช่องตาราง')['content']];
        $content = $this->content('ภาษาไทย', [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Sarabun', 'fontSize' => '18pt', 'color' => '#2563eb', 'backgroundColor' => null, 'lineHeight' => null]]]);
        $content['content'][] = ['type' => 'table', 'attrs' => ['columnSize' => '40,60'], 'content' => [['type' => 'tableRowGroup', 'content' => [['type' => 'tableRow', 'content' => [$cell]]]]]];
        $object = [...$this->shape(), 'type' => 'textbox', 'content' => $this->content('ข้อความในกล่อง'), 'anchor' => ['kind' => 'paragraph', 'id' => 'para-1', 'offsetY' => 15.5]];
        $id = $this->actingAs($this->owner)->postJson('/api/documents', ['content' => $content, 'objects' => [$object]])->assertCreated()->json('document.id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.content', $content)->assertJsonPath('document.objects.0.anchor.offsetY', 15.5)->assertJsonPath('document.objects.0.content', $object['content']);
    }

    public function test_record_import_contains_an_editable_attendance_table_in_original_present_student_order(): void
    {
        $record = TeachingRecord::create(['id' => 'att-source', 'tambon' => 't1', 'week' => 8, 'date' => '2026-10-22', 'year' => '2569', 'subject' => 'ภาษาไทย', 'created_by' => $this->owner->id]);
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => 'S2', 'full_name' => 'บุคคลลำดับแรก', 'present' => 1, 'learning_method' => 'ออนไลน์']);
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => 'S0', 'full_name' => 'ผู้เรียนที่ไม่ได้มา', 'present' => 0]);
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => 'S1', 'full_name' => 'บุคคลลำดับสอง', 'present' => 1]);
        $document = $this->actingAs($this->owner)->postJson('/api/documents/from-record/'.$record->id, [])->assertCreated()->json('document');
        $nodes = $document['content']['content'];
        $table = collect($nodes)->firstWhere('type', 'table');
        $this->assertCount(3, $table['content']);
        $this->assertSame('tableHeader', $table['content'][0]['content'][0]['type']);
        $this->assertSame('S2', $table['content'][1]['content'][1]['content'][0]['content'][0]['text']);
        $this->assertSame('S1', $table['content'][2]['content'][1]['content'][0]['content'][0]['text']);
        $this->assertSame('ออนไลน์', $table['content'][1]['content'][4]['content'][0]['content'][0]['text']);
        $this->assertSame('พบกลุ่ม', $table['content'][2]['content'][4]['content'][0]['content'][0]['text']);
        $this->assertSame(null, $table['content'][1]['content'][0]['attrs']['colwidth']);
        $this->putJson('/api/documents/'.$document['id'], ['revision' => 1, 'content' => $document['content']])->assertOk()->assertJsonPath('document.content', $document['content']);
        $this->assertDatabaseCount('record_attendance', 3);
    }

    public function test_generated_table_plus_json_with_38_rows_and_cell_alignment_can_save_and_reopen(): void
    {
        $rows = [];
        for ($row = 0; $row < 38; $row++) {
            $cells = [];
            foreach ([null, 'left', 'center', 'right', 'justify'] as $column => $align) {
                $cells[] = [
                    'type' => $row === 0 ? 'tableHeader' : 'tableCell',
                    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null, 'align' => $align],
                    'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => null, 'id' => "cell-{$row}-{$column}", 'lineHeight' => null, 'spaceBefore' => 0, 'spaceAfter' => 8, 'indent' => 0], 'content' => [['type' => 'text', 'text' => "ภาษาไทย แถว {$row} ช่อง {$column}"]]]],
                ];
            }
            $rows[] = ['type' => 'tableRow', 'content' => $cells];
        }
        $content = ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['columnSize' => '20,20,20,20,20'], 'content' => $rows]]];
        $id = $this->actingAs($this->owner)->postJson('/api/documents', ['content' => $content])->assertCreated()->json('document.id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.content', $content);
        $this->putJson('/api/documents/'.$id, ['revision' => 1, 'content' => $content])->assertOk()->assertJsonPath('document.revision', 2)->assertJsonPath('document.content', $content);
        $content['content'][0]['content'][0]['content'][0]['attrs']['align'] = 'left;position:fixed';
        $this->putJson('/api/documents/'.$id, ['revision' => 2, 'content' => $content])->assertUnprocessable()
            ->assertJsonValidationErrors('content.content.0.content.0.content.0.attrs.align');
        $this->assertSame(2, Document::query()->findOrFail($id)->revision);
    }

    public function test_thai_distributed_alignment_round_trips_paragraphs_headings_cells_and_record_bodies(): void
    {
        $paragraph = ['type' => 'paragraph', 'attrs' => ['id' => 'thai-paragraph', 'textAlign' => 'thai-distributed'], 'content' => [['type' => 'text', 'text' => 'น้ำ กำลังเรียนรู้ภาษาไทย']]];
        $content = ['type' => 'doc', 'content' => [
            $paragraph,
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'thai-distributed'], 'content' => [['type' => 'text', 'text' => 'หัวข้อภาษาไทย']]],
            ['type' => 'recordBody', 'attrs' => ['align' => 'thai-distributed'], 'content' => [$paragraph]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['align' => 'thai-distributed', 'textAlign' => 'thai-distributed'], 'content' => [$paragraph]]]]]],
        ]];
        $id = $this->actingAs($this->owner)->postJson('/api/documents', ['content' => $content])->assertCreated()->assertJsonPath('document.content', $content)->json('document.id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.content', $content);
        $this->putJson('/api/documents/'.$id, ['revision' => 1, 'content' => $content])->assertOk()->assertJsonPath('document.content', $content);
        $content['content'][0]['attrs']['textAlign'] = 'thai-distributed;position:fixed';
        $this->putJson('/api/documents/'.$id, ['revision' => 2, 'content' => $content])->assertUnprocessable()->assertJsonValidationErrors('content.content.0.attrs.textAlign');
    }

    public function test_record_import_respects_hidden_attendance_and_image_page_settings(): void
    {
        $record = TeachingRecord::create(['id' => 'hidden-att-source', 'tambon' => 't1', 'week' => 8, 'date' => '2026-10-22', 'year' => '2569', 'subject' => 'ภาษาไทย', 'created_by' => $this->owner->id]);
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => 'S1', 'full_name' => 'ผู้เรียน', 'present' => 1]);
        RecordDocumentLayout::create(['record_id' => $record->id, 'settings' => ['show_attendance_page' => '0', 'show_image_page' => '0'], 'content' => [], 'updated_by' => $this->owner->id]);
        Storage::disk('public')->put('records/hidden.png', UploadedFile::fake()->image('hidden.png')->get());
        RecordImage::create(['record_id' => $record->id, 'file_path' => 'storage/records/hidden.png']);
        $document = $this->actingAs($this->owner)->postJson('/api/documents/from-record/'.$record->id, [])->assertCreated()->json('document');
        $this->assertSame([], $document['objects']);
        $this->assertSame([], $document['assets']);
        $this->assertSame([], array_values(array_filter($document['content']['content'], static fn (array $node): bool => $node['type'] === 'table')));
        Storage::disk('public')->assertExists('records/hidden.png');
    }

    public function test_text_run_whitespace_and_empty_attributes_survive_the_http_middleware_and_reload(): void
    {
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => null], 'content' => [
            ['type' => 'text', 'text' => '  ภาษาไทย ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' และอังกฤษ  '],
        ]]]];
        $object = [...$this->shape(), 'type' => 'textbox', 'content' => $content];
        $response = $this->actingAs($this->owner)->postJson('/api/documents', ['content' => $content, 'objects' => [$object], 'pageSettings' => ['header' => '', 'footer' => '  ท้ายกระดาษ  ']])->assertCreated();
        $id = $response->json('document.id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.content', $content)
            ->assertJsonPath('document.objects.0.content', $content)->assertJsonPath('document.pageSettings.header', '')
            ->assertJsonPath('document.pageSettings.footer', '  ท้ายกระดาษ  ');
        $before = $response->json('document.updatedAt');
        $this->travel(2)->seconds();
        $after = $this->putJson('/api/documents/'.$id, ['revision' => 1, 'title' => 'Updated timestamp'])->assertOk()->json('document.updatedAt');
        $this->assertNotSame($before, $after);
    }

    public function test_duplicate_copies_private_images_and_keeps_unsaved_text_independent_of_the_source(): void
    {
        $source = $this->actingAs($this->owner)->postJson('/api/documents', [])->assertCreated()->json('document');
        $asset = $this->postJson('/api/documents/'.$source['id'].'/assets', ['file' => UploadedFile::fake()->image('copy.png', 150, 100)])->assertCreated()->json('asset');
        $content = $this->content('ข้อความล่าสุดที่ยังไม่บันทึก');
        $content['content'][0]['content'][] = ['type' => 'image', 'attrs' => ['src' => $asset['url'], 'assetId' => $asset['id']]];
        $object = [...$this->shape(), 'type' => 'image', 'src' => $asset['url'], 'assetId' => $asset['id']];
        $copy = $this->postJson('/api/documents/'.$source['id'].'/duplicate', ['title' => 'สำเนา', 'content' => $content, 'objects' => [$object]])->assertCreated()->assertJsonPath('document.revision', 1)->json('document');
        $this->assertNotSame($source['id'], $copy['id']);
        $this->assertNotSame($asset['id'], $copy['assets'][0]['id']);
        $this->assertSame($copy['assets'][0]['url'], $copy['content']['content'][0]['content'][1]['attrs']['src']);
        $this->assertSame($copy['assets'][0]['id'], $copy['objects'][0]['assetId']);
        $this->assertSame($source['content'], Document::findOrFail($source['id'])->content_json);
        $other = $this->user('dup-other', 'tambon', 't2');
        $this->actingAs($other)->postJson('/api/documents/'.$source['id'].'/duplicate', [])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson('/api/documents/'.$source['id'])->assertOk();
        $this->get($copy['assets'][0]['url'])->assertOk();
        $this->getJson('/api/documents/'.$copy['id'])->assertOk()->assertJsonPath('document.content.content.0.content.0.text', 'ข้อความล่าสุดที่ยังไม่บันทึก');
    }

    public function test_record_import_uses_reviewed_settings_and_signature_over_later_global_changes(): void
    {
        $record = TeachingRecord::create(['id' => 'frozen-source', 'tambon' => 't1', 'week' => 8, 'date' => '2026-10-22', 'year' => '2569', 'subject' => 'ภาษาไทย', 'created_by' => $this->owner->id]);
        RecordAttendance::create(['record_id' => $record->id, 'student_id' => 'S1', 'full_name' => 'ผู้เรียน', 'present' => 1]);
        $reviewed = app(RecordDocumentOptions::class)->settings(['show_attendance_page' => '0', 'sig1_name' => 'ชื่อที่ตรวจเอกสารแล้ว', 'paper_orientation' => 'landscape']);
        app(RecordRevisionService::class)->capture($record, $this->owner, $reviewed);
        Setting::updateOrCreate(['setting_key' => 'show_attendance_page'], ['setting_value' => '1']);
        Setting::updateOrCreate(['setting_key' => 'sig1_name'], ['setting_value' => 'ชื่อใหม่ในตั้งค่ากลาง']);
        $document = $this->actingAs($this->owner)->postJson('/api/documents/from-record/'.$record->id, [])->assertCreated()->json('document');
        $this->assertSame('landscape', $document['pageSettings']['orientation']);
        $json = json_encode($document['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ชื่อที่ตรวจเอกสารแล้ว', $json);
        $this->assertStringNotContainsString('ชื่อใหม่ในตั้งค่ากลาง', $json);
        $this->assertStringContainsString('22/10/2569', $json);
        $this->assertSame([], array_values(array_filter($document['content']['content'], static fn (array $node): bool => $node['type'] === 'table')));
    }

    private function user(string $id, string $role, ?string $tambon): User
    {
        return User::create(['id' => $id, 'name' => $id, 'username' => $id, 'password' => Hash::make('test-password'), 'role' => $role, 'tambon' => $tambon]);
    }

    private function content(string $text = 'ภาษาไทย', array $marks = []): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'para-1', 'textAlign' => 'left', 'lineHeight' => 1.5, 'spaceBefore' => 0, 'spaceAfter' => 6, 'indent' => 10], 'content' => [['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])]]]]];
    }

    private function shape(): array
    {
        return ['id' => 'shape-1', 'type' => 'shape', 'page' => 1, 'x' => 24, 'y' => 32, 'width' => 60, 'height' => 40, 'rotation' => 15, 'zIndex' => 1, 'opacity' => 0.8, 'wrap' => 'square', 'anchor' => ['kind' => 'page', 'page' => 1], 'shape' => 'rect', 'fill' => '#dbeafe', 'border' => '#2563eb', 'borderWidth' => 1, 'padding' => 3, 'lockAspectRatio' => false];
    }
}
