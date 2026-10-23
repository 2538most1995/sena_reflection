<?php

namespace Tests\Unit;

use App\Services\DocumentSchema;
use App\Services\RecordEditorTemplate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordEditorTemplateTest extends TestCase
{
    public function test_native_template_keeps_the_original_form_geometry_and_editable_fields(): void
    {
        $snapshot = $this->snapshot();
        $model = app(RecordEditorTemplate::class)->build($snapshot);
        app(DocumentSchema::class)->validate($model);
        $nodes = $model['content']['content'];
        $this->assertSame('บันทึกหลังการสอนการเรียนรู้รายสัปดาห์', $nodes[0]['content'][0]['text']);
        $this->assertSame('recordTitle', $nodes[0]['attrs']['role']);
        $this->assertSame('THSarabunNew', $model['pageSettings']['fontFamily']);
        $this->assertSame(16.0, $model['pageSettings']['fontSize']);
        $this->assertFalse($model['pageSettings']['pageNumbers']);
        $this->assertEqualsWithDelta(94, $model['pageSettings']['margins']['left'] * 96 / 25.4, 0.001);
        $this->assertEqualsWithDelta(57, $model['pageSettings']['margins']['top'] * 96 / 25.4, 0.001);
        $this->assertSame([18.0, 22.0, 36.0, 16.0, 8.0], array_column(array_column($nodes[1]['content'], 'attrs'), 'width'));
        $this->assertSame('ตำบลบ้านแพน', $nodes[1]['content'][1]['content'][0]['text']);
        $this->assertSame('กันยายน', $nodes[2]['content'][5]['content'][0]['text']);
        $this->assertSame('2569', $nodes[2]['content'][7]['content'][0]['text']);
        $fields = $this->fields($model['content']);
        $this->assertContains('record_subject', $fields);
        $this->assertContains('record_result', $fields);
        $this->assertContains('record_supervisor_comment', $fields);
        $this->assertContains('sig3_pos', $fields);
        $signatures = $this->nodesOfType($model['content'], 'recordSignature');
        $this->assertSame(['right', 'right', 'center'], array_column(array_column($signatures, 'attrs'), 'align'));
        $this->assertSame([430, 430, 430], array_column(array_column($signatures, 'attrs'), 'width'));
        $this->assertSame([48.0, 40.0, 38.0], array_column(array_column($signatures, 'attrs'), 'marginTop'));
        $this->assertSame(180.0, $signatures[0]['content'][0]['content'][1]['attrs']['width']);
        $this->assertSame('px', $signatures[0]['content'][0]['content'][1]['attrs']['unit']);
    }

    public function test_newlines_remain_in_one_official_paragraph_and_existing_rich_marks_survive(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['record']['result'] = "ข้อความแรก\n  ข้อความบรรทัดใหม่";
        $snapshot['settings']['document_rich_text'] = json_encode(['record_result' => ['text' => $snapshot['record']['result'], 'html' => '<b>ข้อความแรก</b><br><span style="font-size:18pt;color:#166534">  ข้อความบรรทัดใหม่</span>']], JSON_UNESCAPED_UNICODE);
        $model = app(RecordEditorTemplate::class)->build($snapshot);
        app(DocumentSchema::class)->validate($model);
        $body = $this->nodesOfType($model['content'], 'recordBody')[0];
        $this->assertCount(1, $body['content']);
        $this->assertSame('bold', $body['content'][0]['content'][0]['marks'][0]['type']);
        $this->assertSame('hardBreak', $body['content'][0]['content'][1]['type']);
        $this->assertSame('  ข้อความบรรทัดใหม่', $body['content'][0]['content'][2]['text']);
        $this->assertSame(['fontSize' => '18pt', 'color' => '#166534'], $body['content'][0]['content'][2]['marks'][0]['attrs']);
        $this->assertSame(42.0, $body['attrs']['firstIndent']);
        $this->assertSame(26.0, $body['attrs']['lineSpacing']);
    }

    public function test_template_schema_rejects_active_styles_and_unknown_source_field_bindings(): void
    {
        $model = app(RecordEditorTemplate::class)->build($this->snapshot());
        $model['content']['content'][1]['content'][1]['attrs']['field'] = 'password';
        $this->expectException(ValidationException::class);
        app(DocumentSchema::class)->validate($model);
    }

    public function test_compact_legacy_line_spacing_remains_valid_without_moving_the_baselines(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['settings'] = ['pdf_font_size_pt' => '20', 'pdf_line_spacing' => '20'];
        $model = app(RecordEditorTemplate::class)->build($snapshot);
        $validated = app(DocumentSchema::class)->validate($model);

        $this->assertEqualsWithDelta(0.75, $validated['pageSettings']['lineHeight'], 0.0001);
        $this->assertSame(20.0, $this->nodesOfType($validated['content'], 'recordBody')[0]['attrs']['lineSpacing']);
    }

    private function snapshot(): array
    {
        return ['record' => ['subject' => 'อาชญากรรมบนโลกออนไลน์', 'subject_code' => 'สค0200037', 'tambon_name' => 'บ้านแพน', 'class_name' => 'ศกร.ระดับตำบลบ้านแพน', 'level' => 'มัธยมศึกษาตอนปลาย', 'week' => 16, 'date' => '2026-09-30', 'semester' => '1', 'year' => '2569', 'total' => 26, 'present' => 7, 'result' => 'จัดกิจกรรมร่วมกัน', 'problem' => 'นักศึกษาต้องทำงาน', 'suggestion' => 'จัดกิจกรรมออนไลน์', 'teacher_name' => 'ครูทดสอบ'], 'settings' => [], 'images' => [], 'attendance' => []];
    }

    private function fields(array $node): array
    {
        return array_values(array_filter([$node['attrs']['field'] ?? null, ...array_merge([], ...array_map(fn (array $child): array => $this->fields($child), $node['content'] ?? []))]));
    }

    private function nodesOfType(array $node, string $type): array
    {
        return [...($node['type'] === $type ? [$node] : []), ...array_merge([], ...array_map(fn (array $child): array => $this->nodesOfType($child, $type), $node['content'] ?? []))];
    }
}
