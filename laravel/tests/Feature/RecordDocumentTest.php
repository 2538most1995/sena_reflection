<?php

namespace Tests\Feature;

use App\Models\RecordDocumentLayout;
use App\Models\RecordImage;
use App\Models\RecordRevision;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\DraftImagePath;
use App\Services\LegacyPrintService;
use App\Services\RecordDocumentOptions;
use App\Services\RecordRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecordDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private TeachingRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        Tambon::create(['id' => 't2', 'name' => 'สามกอ']);
        $this->teacher = User::create([
            'id' => 'u1', 'name' => 'ครูเสนา', 'username' => 'teacher',
            'password' => Hash::make('secret123'), 'role' => 'tambon', 'tambon' => 't1',
        ]);
        $this->record = TeachingRecord::create([
            'id' => 'record-document-001', 'tambon' => 't1', 'week' => 3, 'date' => '2026-09-01',
            'level' => 'มัธยมศึกษาตอนต้น', 'semester' => '1', 'year' => '2569',
            'subject' => 'ทักษะการเรียนรู้', 'subject_code' => 'ทช21001',
            'class_group_code' => 'G01', 'class_name' => 'กลุ่มเสนา', 'total' => 2, 'present' => 1,
            'result' => 'ผลเดิม', 'problem' => 'ปัญหาเดิม', 'suggestion' => 'ข้อเสนอเดิม', 'created_by' => 'u1',
        ]);
    }

    public function test_teacher_can_open_and_save_a_per_record_document_layout(): void
    {
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('records/document')
                ->where('record.id', $this->record->id)
                ->where('settings.paper_orientation', 'portrait')
                ->where('settings.image_layout_mode', 'free')
                ->where('settings.image_1_lock_aspect', '1')
                ->where('content.record_result', 'ผลเดิม')
                ->where('hasSavedLayout', false));

        $this->put(route('records.document.update', $this->record), [
            'settings' => [
                'paper_orientation' => 'landscape',
                'show_image_page' => '0',
                'show_attendance_page' => '1',
                'image_1_width_px' => '420',
                'image_1_height_px' => '280',
                'image_1_x_px' => '130',
                'image_1_y_px' => '72',
                'image_1_object_fit' => 'cover',
                'image_1_rotation_deg' => '90',
                'image_1_lock_aspect' => '1',
                'image_1_crop_x' => '15',
                'image_1_crop_y' => '85',
            ],
            'content' => ['record_subject' => 'รายวิชาสำหรับเอกสาร', 'record_result' => 'ผลที่แก้เฉพาะ PDF'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $layout = RecordDocumentLayout::query()->findOrFail($this->record->id);
        $this->assertSame('landscape', $layout->settings['paper_orientation']);
        $this->assertSame('420', $layout->settings['image_1_width_px']);
        $this->assertSame('130', $layout->settings['image_1_x_px']);
        $this->assertSame('cover', $layout->settings['image_1_object_fit']);
        $this->assertSame('90', $layout->settings['image_1_rotation_deg']);
        $this->assertSame('1', $layout->settings['image_1_lock_aspect']);
        $this->assertSame('15', $layout->settings['image_1_crop_x']);
        $this->assertSame('85', $layout->settings['image_1_crop_y']);
        $this->assertSame([], $layout->content);
        $this->assertSame('ผลที่แก้เฉพาะ PDF', $this->record->fresh()->result);
        $this->assertSame('รายวิชาสำหรับเอกสาร', $this->record->fresh()->subject);
        $this->assertSame($this->teacher->id, $layout->updated_by);
        $this->assertCount(2, RecordRevision::query()->where('record_id', $this->record->id)->get());
        $this->assertSame('ผลเดิม', RecordRevision::query()->where('record_id', $this->record->id)->orderBy('revision')->firstOrFail()->snapshot['record']['result']);
    }

    public function test_document_spacing_and_explicit_blank_lines_survive_save_and_export_snapshot(): void
    {
        $settings = [
            'sig1_mt' => '0', 'sig2_mt' => '24', 'sig3_mt' => '36',
            'sig2_block_offset_y' => '-18', 'sig3_block_offset_y' => '42',
            'record_paragraph_spacing_px' => '12', 'record_text_indent_px' => '64', 'record_text_align' => 'left',
            'sig1_pos' => "\nครูผู้สอน\n\n", 'sig2_pos' => "  หัวหน้างาน\nเสนา  ", 'sig3_pos' => "ผู้อำนวยการ\n\n",
        ];
        $text = "\n  บรรทัดแรก\n\n\nบรรทัดสุดท้าย  \n\n";

        $this->actingAs($this->teacher)->putJson(route('records.document.update', $this->record), [
            'settings' => $settings,
            'content' => ['record_subject' => $this->record->subject, 'record_result' => $text],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $layout = RecordDocumentLayout::query()->findOrFail($this->record->id);
        foreach ($settings as $key => $value) {
            $this->assertSame($value, $layout->settings[$key]);
        }
        $this->assertSame($text, $this->record->fresh()->result);
        $snapshot = app(LegacyPrintService::class)->savedSnapshot($this->record, $this->teacher);
        $this->assertSame($text, $snapshot['content']['record_result']);
        $this->assertSame($text, $snapshot['record']['result']);
        foreach ($settings as $key => $value) {
            $this->assertSame($value, $snapshot['settings'][$key]);
        }
    }

    public function test_document_defaults_preserve_legacy_and_explicit_signature_spacing(): void
    {
        Setting::query()->create(['setting_key' => 'sig1_mt', 'setting_value' => '48']);
        Setting::query()->create(['setting_key' => 'sig2_mt', 'setting_value' => '40']);
        $options = app(RecordDocumentOptions::class);

        $defaults = $options->settings();
        $this->assertSame('48', $defaults['sig1_mt']);
        $this->assertSame('40', $defaults['sig2_mt']);
        $this->assertSame('16', $defaults['sig3_mt']);
        $explicit = $options->settings(['sig1_mt' => '0', 'sig2_mt' => '9', 'record_text_indent_px' => '0']);
        $this->assertSame('0', $explicit['sig1_mt']);
        $this->assertSame('9', $explicit['sig2_mt']);
        $this->assertSame('0', $explicit['record_text_indent_px']);
    }

    public function test_document_rejects_unsupported_spacing_and_text_alignment(): void
    {
        $this->actingAs($this->teacher);
        foreach ([
            'sig1_mt' => '-1', 'sig2_mt' => '241', 'sig3_mt' => '1.5',
            'sig2_block_offset_y' => '-121', 'sig3_block_offset_y' => '241',
            'record_paragraph_spacing_px' => '81', 'record_text_indent_px' => '161',
            'record_text_align' => 'unsafe',
        ] as $key => $value) {
            $this->putJson(route('records.document.update', $this->record), [
                'settings' => [$key => $value],
                'content' => ['record_subject' => $this->record->subject],
            ])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseMissing('record_document_layouts', ['record_id' => $this->record->id]);
        $this->assertSame('ผลเดิม', $this->record->fresh()->result);
    }

    public function test_saved_print_uses_the_frozen_reviewed_snapshot_and_geometry(): void
    {
        RecordImage::query()->create(['record_id' => $this->record->id, 'file_path' => 'storage/records/reviewed.jpg']);
        RecordDocumentLayout::query()->create([
            'record_id' => $this->record->id,
            'settings' => ['paper_orientation' => 'landscape', 'image_1_x_px' => '79', 'image_1_y_px' => '123', 'image_1_width_px' => '418', 'image_1_height_px' => '267', 'image_1_rotation_deg' => '90', 'image_1_object_fit' => 'cover', 'image_1_crop_x' => '19'],
            'content' => [], 'updated_by' => $this->teacher->id,
        ]);
        $reviewed = app(RecordRevisionService::class)->capture($this->record, $this->teacher)->snapshot;
        Setting::query()->updateOrCreate(['setting_key' => 'pdf_font_size_pt'], ['setting_value' => '20']);
        Setting::query()->updateOrCreate(['setting_key' => 'image_layout_mode'], ['setting_value' => 'grid']);
        $this->assertEquals($reviewed, app(LegacyPrintService::class)->savedSnapshot($this->record, $this->teacher));
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('snapshotHtml')->once()->withArgs(fn (array $snapshot, User $user): bool => $snapshot == $reviewed && $user->is($this->teacher))
            ->andReturn('<!doctype html>reviewed document');
        $this->assertSame('<!doctype html>reviewed document', $print->html($this->record, $this->teacher));
        $this->assertSame('418', $reviewed['settings']['image_1_width_px']);
        $this->assertSame('19', $reviewed['settings']['image_1_crop_x']);
        $unchangedRevision = app(RecordRevisionService::class)->capture($this->record, $this->teacher);
        $this->assertSame(1, $unchangedRevision->revision);
        $this->assertSame($reviewed, $unchangedRevision->snapshot);

        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('settings.pdf_font_size_pt', '16')->where('settings.image_layout_mode', 'free')->where('settings.image_1_x_px', '79'));
    }

    public function test_printing_without_attendance_changes_only_a_snapshot_copy(): void
    {
        $reviewed = app(RecordRevisionService::class)->capture($this->record, $this->teacher)->snapshot;
        $expected = $reviewed;
        $expected['settings']['show_attendance_page'] = '0';
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('snapshotHtml')->once()->withArgs(fn (array $snapshot): bool => $snapshot == $expected)->andReturn('<!doctype html>without attendance');
        $print->html($this->record, $this->teacher, true);
        $this->assertSame($reviewed, RecordRevision::query()->where('record_id', $this->record->id)->firstOrFail()->snapshot);
    }

    public function test_revision_accepts_reviewed_renderer_settings_and_reset_explicitly_refreshes_defaults(): void
    {
        $revisions = app(RecordRevisionService::class);
        $reviewedSettings = app(RecordDocumentOptions::class)->settings(['paper_orientation' => 'landscape', 'pdf_font_size_pt' => '14']);
        $reviewedSettings['signature_font_size_pt'] = '13';
        $first = $revisions->capture($this->record, $this->teacher, $reviewedSettings);
        $this->assertSame('13', $first->snapshot['settings']['signature_font_size_pt']);
        $this->assertSame('landscape', $first->snapshot['settings']['paper_orientation']);
        Setting::query()->updateOrCreate(['setting_key' => 'pdf_font_size_pt'], ['setting_value' => '18']);
        $this->assertSame('14', $revisions->capture($this->record, $this->teacher)->snapshot['settings']['pdf_font_size_pt']);

        $this->actingAs($this->teacher)->delete(route('records.document.destroy', $this->record))->assertSessionHasNoErrors();
        $reset = app(LegacyPrintService::class)->savedSnapshot($this->record, $this->teacher);
        $this->assertSame('18', $reset['settings']['pdf_font_size_pt']);
        $this->assertSame('portrait', $reset['settings']['paper_orientation']);
    }

    public function test_legacy_record_update_prints_current_text_with_frozen_geometry(): void
    {
        RecordDocumentLayout::query()->create([
            'record_id' => $this->record->id, 'settings' => [],
            'content' => ['record_result' => 'สำเนาข้อความเก่าที่เคยเก็บในเอกสาร'],
            'updated_by' => $this->teacher->id,
        ]);
        $revision = app(RecordRevisionService::class)->capture($this->record, $this->teacher, app(RecordDocumentOptions::class)->settings(['image_1_width_px' => '420', 'paper_orientation' => 'landscape']));
        TeachingRecord::query()->whereKey($this->record->id)->update(['result' => 'ข้อความล่าสุดจาก API เดิม', 'subject' => 'รายวิชาที่แก้จากระบบเดิม']);
        Setting::query()->updateOrCreate(['setting_key' => 'pdf_font_size_pt'], ['setting_value' => '20']);
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('snapshotHtml')->once()->withArgs(fn (array $snapshot): bool => $snapshot['content']['record_result'] === 'ข้อความล่าสุดจาก API เดิม'
            && $snapshot['record']['subject'] === 'รายวิชาที่แก้จากระบบเดิม'
            && $snapshot['settings']['image_1_width_px'] === '420'
            && $snapshot['settings']['paper_orientation'] === 'landscape'
            && $snapshot['settings']['pdf_font_size_pt'] === '16')->andReturn('<!doctype html>current canonical text');
        $this->assertSame('<!doctype html>current canonical text', $print->html($this->record->fresh(), $this->teacher));
        $this->assertSame('ผลเดิม', $revision->fresh()->snapshot['content']['record_result']);
        $exportRevision = app(RecordRevisionService::class)->capture($this->record, $this->teacher);
        $this->assertSame('ข้อความล่าสุดจาก API เดิม', $exportRevision->snapshot['content']['record_result']);
        $this->assertSame('รายวิชาที่แก้จากระบบเดิม', $exportRevision->snapshot['content']['record_subject']);
        $this->assertSame('420', $exportRevision->snapshot['settings']['image_1_width_px']);
    }

    public function test_old_image_offsets_are_carried_into_the_free_canvas(): void
    {
        RecordDocumentLayout::create([
            'record_id' => $this->record->id,
            'settings' => ['image_1_offset_x' => '34', 'image_1_offset_y' => '12'],
            'content' => [],
            'updated_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.image_1_x_px', '34')
                ->where('settings.image_1_y_px', '20'));
    }

    public function test_private_draft_image_requires_snapshot_membership_and_its_storage_subtree(): void
    {
        $directory = storage_path('app/private/teaching-drafts/pdf-test-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        $path = $directory.'/image.jpg';
        file_put_contents($path, 'fixture');
        try {
            $this->assertSame(realpath($path), DraftImagePath::resolve($path, [['file_path' => $path]]));
            $this->assertNull(DraftImagePath::resolve($path, []));
            $outside = base_path('composer.json');
            $this->assertNull(DraftImagePath::resolve($outside, [['file_path' => $outside]]));
            $link = $directory.'/outside.jpg';
            symlink($outside, $link);
            $this->assertNull(DraftImagePath::resolve($link, [['file_path' => $link]]));
        } finally {
            if (is_link($directory.'/outside.jpg')) {
                unlink($directory.'/outside.jpg');
            }
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_legacy_teacher_signature_defaults_keep_scope_and_record_overrides(): void
    {
        foreach (['sig1_name' => 'ชื่อส่วนกลาง', 'tambon:t1:sig1_name' => 'ชื่อประจำตำบล', 'user:u1:sig1_name' => 'ชื่อครูเฉพาะบัญชี'] as $key => $value) {
            Setting::query()->create(['setting_key' => $key, 'setting_value' => $value]);
        }
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('settings.sig1_name', 'ชื่อครูเฉพาะบัญชี'));

        RecordDocumentLayout::query()->create(['record_id' => $this->record->id, 'settings' => ['sig1_name' => 'ชื่อฉบับเอกสาร'], 'content' => [], 'updated_by' => $this->teacher->id]);
        $this->get(route('records.document.edit', $this->record))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('settings.sig1_name', 'ชื่อฉบับเอกสาร'));
        $district = User::query()->create(['id' => 'district-scope', 'username' => 'district-scope', 'name' => 'ผู้ดูแล', 'password' => Hash::make('secret'), 'role' => 'district']);
        $resolved = app(RecordDocumentOptions::class)->legacySettings('t1', $district);
        $this->assertSame('ชื่อประจำตำบล', $resolved['sig1_name']);
    }

    public function test_preview_uses_actual_record_and_unsaved_document_values(): void
    {
        $text = "\n  ข้อความจาก Preview\n\n\n";
        $snapshot = ['schema_version' => 1, 'record' => ['id' => $this->record->id], 'images' => [], 'attendance' => [], 'settings' => [], 'content' => []];
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotForRecord')
            ->once()
            ->withArgs(fn ($record, $settings, $content, $user) => $record->is($this->record)
                && $user->is($this->teacher)
                && $settings['paper_orientation'] === 'portrait'
                && $content['record_result'] === $text)
            ->andReturn($snapshot);
        $print->shouldReceive('snapshotHtml')
            ->once()
            ->withArgs(fn ($actual, $user, $editor) => $actual === $snapshot && $user->is($this->teacher) && $editor === true)
            ->andReturn('<!doctype html><html lang="th"><body>preview</body></html>');

        $this->actingAs($this->teacher)->postJson(route('records.document.preview', $this->record), [
            'settings' => ['paper_orientation' => 'portrait'],
            'content' => ['record_result' => $text],
        ])->assertOk()->assertHeader('content-type', 'text/html; charset=UTF-8')->assertSee('preview');
    }

    public function test_pdf_preview_renders_unsaved_snapshot_with_the_production_service(): void
    {
        $snapshot = ['schema_version' => 1, 'record' => ['id' => $this->record->id], 'images' => [], 'attendance' => [], 'settings' => [], 'content' => ['record_result' => 'ยังไม่บันทึก']];
        $print = $this->mock(LegacyPrintService::class);
        $print->shouldReceive('snapshotForRecord')->once()->withArgs(
            fn ($record, $settings, $content, $user) => $record->is($this->record)
                && $settings['paper_orientation'] === 'portrait'
                && $content['record_result'] === 'ยังไม่บันทึก'
                && $user->is($this->teacher)
        )->andReturn($snapshot);
        $print->shouldReceive('snapshotPdf')->once()->with($snapshot, $this->teacher)->andReturn('%PDF-1.7 preview');

        $this->actingAs($this->teacher)->postJson(route('records.document.pdf-preview', $this->record), [
            'settings' => ['paper_orientation' => 'portrait'],
            'content' => ['record_result' => 'ยังไม่บันทึก'],
        ])->assertOk()->assertHeader('content-type', 'application/pdf')->assertContent('%PDF-1.7 preview');
    }

    public function test_image_layout_is_saved_by_image_id_and_survives_reordering(): void
    {
        $first = RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/first.jpg']);
        $second = RecordImage::create(['record_id' => $this->record->id, 'file_path' => 'uploads/second.jpg']);

        $this->actingAs($this->teacher)->put(route('records.document.update', $this->record), [
            'settings' => ['image_1_x_px' => '41', 'image_1_y_px' => '51', 'image_1_crop_x' => '17', 'image_2_x_px' => '401', 'image_2_y_px' => '211'],
            'content' => ['record_subject' => $this->record->subject],
        ])->assertSessionHasNoErrors();

        $layout = RecordDocumentLayout::query()->findOrFail($this->record->id);
        $stable = json_decode($layout->settings['image_layout_by_id'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('41', $stable[(string) $first->id]['x_px']);
        $this->assertSame('401', $stable[(string) $second->id]['x_px']);

        $projected = app(RecordDocumentOptions::class)->settings(
            $layout->settings,
            [(string) $second->id, (string) $first->id],
        );
        $this->assertSame('401', $projected['image_1_x_px']);
        $this->assertSame('41', $projected['image_2_x_px']);
        $this->assertSame('17', $projected['image_2_crop_x']);
    }

    public function test_four_image_moves_survive_stale_client_map_preview_save_reopen_and_pdf(): void
    {
        $images = collect(range(1, 4))->map(fn (int $index): RecordImage => RecordImage::query()->create([
            'record_id' => $this->record->id, 'file_path' => "uploads/round-trip-{$index}.jpg",
        ]));
        $imageIds = $images->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $settings = app(RecordDocumentOptions::class)->settings([], $imageIds);
        $staleMap = $settings['image_layout_by_id'];
        $this->actingAs($this->teacher);
        $print = $this->partialMock(LegacyPrintService::class);
        $capturedPreview = [];
        $capturedPdf = [];
        $print->shouldReceive('snapshotHtml')->twice()->andReturnUsing(function (array $snapshot) use (&$capturedPreview): string {
            $capturedPreview[] = $snapshot;

            return '<!doctype html><html><body>four image preview</body></html>';
        });
        $print->shouldReceive('snapshotPdf')->once()->andReturnUsing(function (array $snapshot) use (&$capturedPdf): string {
            $capturedPdf = $snapshot;

            return '%PDF-1.7 four-image-layout';
        });

        foreach ([0, 1] as $round) {
            foreach (range(1, 4) as $slot) {
                $settings["image_{$slot}_x_px"] = (string) (12 + (($slot - 1) % 2) * 310 + $round * 7);
                $settings["image_{$slot}_y_px"] = (string) (20 + intdiv($slot - 1, 2) * 290 + $round * 11);
                $settings["image_{$slot}_width_px"] = (string) (250 - $slot * 10);
                $settings["image_{$slot}_height_px"] = (string) (180 + $slot * 5);
                $settings["image_{$slot}_object_fit"] = $slot % 2 ? 'fill' : 'cover';
                $settings["image_{$slot}_lock_aspect"] = '0';
            }
            // The browser edits slots locally while this serialized map still
            // contains the previous render. Submitted slots must win on writes.
            $settings['image_layout_by_id'] = $staleMap;
            $payload = ['settings' => $settings, 'content' => [
                'record_subject' => $this->record->subject, 'record_result' => "ข้อความแก้ไขรอบ {$round}",
            ]];
            $this->postJson(route('records.document.preview', $this->record), $payload)->assertOk();
            $this->putJson(route('records.document.update', $this->record), $payload)
                ->assertRedirect()->assertSessionHasNoErrors();
            $stored = RecordDocumentLayout::query()->findOrFail($this->record->id)->settings;
            $snapshot = app(LegacyPrintService::class)->savedSnapshot($this->record, $this->teacher);
            $this->assertSame($stored['image_layout_by_id'], $snapshot['settings']['image_layout_by_id']);
            $map = json_decode($stored['image_layout_by_id'], true, flags: JSON_THROW_ON_ERROR);
            foreach ($imageIds as $index => $imageId) {
                $slot = $index + 1;
                foreach (['x_px', 'y_px', 'width_px', 'height_px', 'object_fit', 'lock_aspect'] as $part) {
                    $key = "image_{$slot}_{$part}";
                    $this->assertSame($settings[$key], $map[$imageId][$part]);
                    $this->assertSame($settings[$key], $stored[$key]);
                    $this->assertSame($settings[$key], $capturedPreview[$round]['settings'][$key]);
                    $this->assertSame($settings[$key], $snapshot['settings'][$key]);
                }
            }
            $this->get(route('records.document.edit', $this->record))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('settings.image_layout_by_id', $stored['image_layout_by_id'])
                    ->where('settings.image_1_x_px', $settings['image_1_x_px'])
                    ->where('settings.image_4_y_px', $settings['image_4_y_px']));
            if ($round === 1) {
                $this->postJson(route('records.document.pdf-preview', $this->record), $payload)->assertOk();
                $this->assertSame($stored['image_layout_by_id'], $capturedPdf['settings']['image_layout_by_id']);
                $this->assertSame($snapshot['content'], $capturedPdf['content']);
            }
            $staleMap = $stored['image_layout_by_id'];
        }
    }

    public function test_replacing_an_image_preserves_survivor_geometry_and_uses_the_vacated_position(): void
    {
        $images = collect(range(1, 4))->map(fn (int $index): RecordImage => RecordImage::query()->create([
            'record_id' => $this->record->id, 'file_path' => "uploads/replacement-{$index}.jpg",
        ]));
        $imageIds = $images->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $initial = app(RecordDocumentOptions::class)->settings([], $imageIds);
        RecordDocumentLayout::query()->create([
            'record_id' => $this->record->id, 'settings' => $initial, 'content' => [], 'updated_by' => $this->teacher->id,
        ]);
        $images->first()->delete();
        $replacement = RecordImage::query()->create(['record_id' => $this->record->id, 'file_path' => 'uploads/replacement-new.jpg']);

        $snapshot = app(LegacyPrintService::class)->savedSnapshot($this->record, $this->teacher);
        $ids = array_column($snapshot['images'], 'id');
        $this->assertSame([$images[1]->id, $images[2]->id, $images[3]->id, $replacement->id], $ids);
        $map = json_decode($snapshot['settings']['image_layout_by_id'], true, flags: JSON_THROW_ON_ERROR);
        $initialMap = json_decode($initial['image_layout_by_id'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($images->slice(1) as $survivor) {
            $this->assertSame($initialMap[(string) $survivor->id], $map[(string) $survivor->id]);
        }
        $this->assertSame('0', $map[(string) $replacement->id]['x_px']);
        $this->assertSame('8', $map[(string) $replacement->id]['y_px']);
        $positions = array_map(fn (array $layout): string => $layout['x_px'].':'.$layout['y_px'], $map);
        $this->assertCount(4, array_unique($positions));
        $this->actingAs($this->teacher)->get(route('records.document.edit', $this->record))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('settings.image_4_x_px', '0')->where('settings.image_4_y_px', '8'));
    }

    public function test_old_document_override_requires_an_explicit_resolution(): void
    {
        RecordDocumentLayout::create([
            'record_id' => $this->record->id,
            'settings' => [],
            'content' => ['record_result' => 'ผลฉบับเอกสารเก่า'],
            'updated_by' => $this->teacher->id,
        ]);

        $payload = ['settings' => ['paper_orientation' => 'portrait'], 'content' => ['record_subject' => $this->record->subject, 'record_result' => 'ผลฉบับเอกสารเก่า']];
        $this->actingAs($this->teacher)->put(route('records.document.update', $this->record), $payload)
            ->assertSessionHasErrors('content_resolution');

        $this->put(route('records.document.update', $this->record), [...$payload, 'content_resolution' => 'record'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ผลเดิม', $this->record->fresh()->result);
    }

    public function test_teacher_cannot_open_preview_save_or_reset_another_tambon_document(): void
    {
        $other = User::create([
            'id' => 'u2', 'name' => 'ครูสามกอ', 'username' => 'other',
            'password' => Hash::make('secret123'), 'role' => 'tambon', 'tambon' => 't2',
        ]);

        $this->actingAs($other)->get(route('records.document.edit', $this->record))->assertForbidden();
        $this->postJson(route('records.document.preview', $this->record), ['settings' => [], 'content' => []])->assertForbidden();
        $this->postJson(route('records.document.pdf-preview', $this->record), ['settings' => [], 'content' => []])->assertForbidden();
        $this->put(route('records.document.update', $this->record), ['settings' => [], 'content' => []])->assertForbidden();
        $this->delete(route('records.document.destroy', $this->record))->assertForbidden();
    }

    public function test_district_can_manage_document_and_teacher_can_restore_defaults(): void
    {
        $district = User::create([
            'id' => 'u0', 'name' => 'ผู้ดูแล', 'username' => 'district',
            'password' => Hash::make('secret123'), 'role' => 'district', 'tambon' => null,
        ]);
        RecordDocumentLayout::create([
            'record_id' => $this->record->id,
            'settings' => ['paper_size' => 'A4', 'paper_orientation' => 'portrait'],
            'content' => ['record_subject' => 'เอกสารเดิม'],
            'updated_by' => $district->id,
        ]);

        $this->actingAs($district)->get(route('records.document.edit', $this->record))->assertOk();
        $this->actingAs($this->teacher)->delete(route('records.document.destroy', $this->record))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('record_document_layouts', ['record_id' => $this->record->id]);
    }
}
