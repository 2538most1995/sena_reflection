<?php

namespace Tests\Unit;

use App\Services\DocumentRichText;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DocumentRichTextTest extends TestCase
{
    public function test_sanitizer_keeps_supported_formatting_and_removes_active_content_and_unsafe_styles(): void
    {
        $source = '<p onclick="alert(1)" style="text-align:center;position:fixed;background-image:url(https://example.test/track)"><b>ไทย</b><span style="font-size:24px;font-family:Tahoma;color:rgb(12, 34, 56);background-color:#FE0;font-weight:700;font-style:italic;text-decoration:underline;line-height:1.5">ข้อความ</span><script>alert(1)</script><svg><text>hidden</text></svg><iframe src="https://example.test"></iframe><img src="x" onerror="alert(1)"></p>';

        $sanitized = DocumentRichText::sanitize($source);

        $this->assertSame('<p style="text-align:center"><b>ไทย</b><span style="font-size:18pt;font-family:Tahoma;color:rgb(12, 34, 56);background-color:#fe0;font-weight:700;font-style:italic;text-decoration:underline;line-height:1.5">ข้อความ</span></p>', $sanitized);
        $this->assertSame($sanitized, DocumentRichText::sanitize($sanitized));
    }

    public function test_legacy_font_tags_are_normalized_and_out_of_range_or_injected_styles_are_removed(): void
    {
        $this->assertSame(
            '<span style="font-family:Sarabun;color:#ff0000;font-size:24pt"><u>ตัวอักษร</u></span><span>อื่น</span>',
            DocumentRichText::sanitize('<font face="Sarabun" color="#FF0000" size="6"><u>ตัวอักษร</u></font><span style="font-size:200pt;font-family:Unknown;color:rgb(999,0,0);line-height:9;display:none;text-align:expression(alert(1))">อื่น</span>'),
        );
    }

    public function test_rich_map_limits_fields_sanitizes_html_and_preserves_thai_newlines(): void
    {
        $source = json_encode([
            'record_result' => ['text' => "ไทย\r\nถัดไป", 'html' => '<div><b>ไทย</b></div><div>ถัดไป</div>'],
            'password' => ['text' => 'secret', 'html' => '<b>secret</b>'],
            'record_problem' => ['text' => 'canonical', 'html' => '<b>different</b>'],
        ], JSON_THROW_ON_ERROR);

        $map = json_decode(DocumentRichText::normalizeMap($source, ['record_result', 'record_problem']), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['record_result' => ['text' => "ไทย\nถัดไป", 'html' => '<div><b>ไทย</b></div><div>ถัดไป</div>']], $map);
        $this->assertSame('<div><b>ไทย</b></div><div>ถัดไป</div>', DocumentRichText::render("ไทย\nถัดไป", 'record_result', json_encode($map, JSON_THROW_ON_ERROR)));
    }

    public function test_render_falls_back_to_escaped_current_content_for_stale_or_forged_html(): void
    {
        $source = json_encode(['record_result' => ['text' => 'original', 'html' => '<b>original</b>']], JSON_THROW_ON_ERROR);

        $this->assertSame("&lt;current&gt;<br>\nnext", DocumentRichText::render("<current>\nnext", 'record_result', $source));
        $forged = json_encode(['record_result' => ['text' => 'current', 'html' => '<b>other</b>']], JSON_THROW_ON_ERROR);
        $this->assertSame('current', DocumentRichText::render('current', 'record_result', $forged));
        $this->assertSame('&lt;script&gt;', DocumentRichText::render('<script>', 'record_result', '{invalid'));
    }

    public function test_invalid_and_oversized_maps_are_rejected_without_a_framework_runtime(): void
    {
        foreach (['{invalid', '[]', '{"record_result":{"text":"text","html":4}}', str_repeat(' ', DocumentRichText::MAX_JSON_BYTES + 1)] as $source) {
            try {
                DocumentRichText::normalizeMap($source, ['record_result']);
                $this->fail('Expected invalid rich text to be rejected');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
