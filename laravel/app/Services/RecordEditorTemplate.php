<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class RecordEditorTemplate
{
    private array $settings = [];

    /** @return array{content:array,pageSettings:array,objects:array} */
    public function build(array $snapshot): array
    {
        $this->settings = $snapshot['settings'] ?? [];
        $record = array_replace($snapshot['record'] ?? [], collect($snapshot['content'] ?? [])->mapWithKeys(fn ($value, $key): array => [str_starts_with($key, 'record_') ? substr($key, 7) : $key => $value])->all());
        $date = ! empty($record['date']) ? CarbonImmutable::parse($record['date']) : null;
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $fontSize = (float) ($this->settings['pdf_font_size_pt'] ?? 16);
        $parts = [$this->paragraph('บันทึกหลังการสอนการเรียนรู้รายสัปดาห์', null, 'recordTitle', [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontSize' => ($fontSize + 4).'pt']]])];
        $parts[] = $this->infoRow([
            ['ศูนย์การเรียนรู้', 18, 'ตำบล'.($record['tambon_name'] ?? ''), 22],
            ['ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ', 36, 'เสนา', 16],
        ]);
        $parts[] = $this->infoRow([
            ['สัปดาห์ที่', 9, $record['week'] ?? '', 8], ['วันที่', 7, $date?->day ?? '', 9],
            ['เดือน', 7, $date ? $months[$date->month] : '', 17], ['พ.ศ.', 7, $date ? $date->year + 543 : '', 13],
        ]);
        $parts[] = $this->infoRow([
            ['ระดับ', 8, $record['level'] ?? '', 27], ['ภาคเรียนที่', 17, $record['semester'] ?? '', 7], ['ปีการศึกษา', 17, $record['year'] ?? '', 9],
        ]);
        if (! empty($record['class_name']) || ! empty($record['class_group_code'])) {
            $key = ! empty($record['class_name']) ? 'class_name' : 'class_group_code';
            $parts[] = $this->infoRow([['กลุ่มเรียน', 12, $record[$key], 88, 'record_'.$key]]);
        }
        $parts[] = $this->infoRow([
            ['รายวิชา :', 10, $record['subject'] ?? '', 36, 'record_subject'], ['รหัสวิชา :', 11, $record['subject_code'] ?? '', 28, 'record_subject_code'],
        ]);
        $parts[] = $this->infoRow([
            ['ผู้เรียนทั้งหมด', 14, $record['total'] ?? '', 8], ['คน เข้าเรียน', 13, $record['present'] ?? '', 8],
            ['คน ไม่มาเรียน', 15, max(0, (int) ($record['total'] ?? 0) - (int) ($record['present'] ?? 0)), 8], ['คน', 7, null, 0],
        ]);
        foreach ([1 => ['result', '๑. ผลการจัดการเรียนการสอน'], 2 => ['problem', '๒. สภาพปัญหาและอุปสรรค'], 3 => ['suggestion', '๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา']] as $index => [$field, $title]) {
            $parts[] = $this->sectionTitle((string) ($this->settings['record_section'.$index.'_title'] ?? $title), 'record_section'.$index.'_title');
            $parts[] = $this->body((string) ($record[$field] ?? ''), 'record_'.$field);
        }
        $parts[] = $this->signature(1, (string) ($record['teacher_name'] ?? ''));
        $parts[] = ['type' => 'recordGroup', 'attrs' => ['spaceBefore' => 18 + $this->number('sig2_block_offset_y'), 'offsetX' => $this->number('sig2_block_offset_x'), 'offsetY' => 0, 'borderTop' => true], 'content' => [
            $this->sectionTitle((string) ($this->settings['sig2_title'] ?? 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'), 'sig2_title', 2),
            $this->body((string) ($record['supervisor_comment'] ?? ''), 'record_supervisor_comment'),
            $this->signature(2),
        ]];
        $parts[] = ['type' => 'recordGroup', 'attrs' => ['spaceBefore' => 24 + $this->number('sig3_block_offset_y'), 'offsetX' => $this->number('sig3_block_offset_x'), 'offsetY' => 0, 'borderTop' => false], 'content' => [
            $this->sectionTitle((string) ($this->settings['sig3_title'] ?? 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา'), 'sig3_title', 3),
            array_replace_recursive($this->paragraph(''), ['attrs' => ['recordLine' => true, 'firstIndent' => 0, 'spaceAfter' => 1.5]]), $this->signature(3),
        ]];
        $page = DocumentSchema::defaultPageSettings();
        $page['orientation'] = $this->settings['paper_orientation'] ?? 'portrait';
        foreach (['top' => 57, 'right' => 76, 'bottom' => 38, 'left' => 94] as $side => $default) {
            $page['margins'][$side] = round($this->number('pdf_margin_'.$side.'_px', $default) * 25.4 / 96, 4);
        }
        $page = [...$page, 'template' => 'weekly-record', 'fontFamily' => 'THSarabunNew', 'fontSize' => $fontSize, 'lineHeight' => $this->number('pdf_line_spacing', 26) / ($fontSize * 96 / 72), 'pageNumbers' => false];
        $objects = [];
        $sourceImages = ($this->settings['show_image_page'] ?? '1') === '1' ? ($snapshot['images'] ?? []) : [];
        $layouts = json_decode($this->settings['image_layout_by_id'] ?? '{}', true) ?: [];
        if ($sourceImages !== []) {
            $heading = $this->paragraph('ภาพประกอบการจัดการเรียนการสอน', null, 'recordTitle', [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontSize' => ($fontSize + 4).'pt']]]);
            $parts = [...$parts, ['type' => 'pageBreak'], $heading, $this->paragraph('')];
            foreach ($sourceImages as $index => $image) {
                if (empty($image['asset_id']) || empty($image['url'])) {
                    continue;
                }
                $layout = $layouts[(string) ($image['source_id'] ?? $image['id'])] ?? [];
                $position = $index + 1;
                $x = (float) ($layout['x_px'] ?? $this->settings["image_{$position}_x_px"] ?? (($index % 2) * 316));
                $y = (float) ($layout['y_px'] ?? $this->settings["image_{$position}_y_px"] ?? (8 + intdiv($index, 2) * 249));
                $width = (float) ($layout['width_px'] ?? $this->settings["image_{$position}_width_px"] ?? 300);
                $height = (float) ($layout['height_px'] ?? $this->settings["image_{$position}_height_px"] ?? 225);
                $objects[] = ['id' => 'record-image-'.$image['asset_id'], 'type' => 'image', 'page' => 2, 'x' => $page['margins']['left'] + $x * 25.4 / 96, 'y' => $page['margins']['top'] + (54 + $y) * 25.4 / 96, 'width' => $width * 25.4 / 96, 'height' => $height * 25.4 / 96, 'rotation' => (float) ($layout['rotation_deg'] ?? $this->settings["image_{$position}_rotation_deg"] ?? 0), 'zIndex' => $position, 'opacity' => 1, 'wrap' => 'square', 'anchor' => ['kind' => 'paragraph', 'id' => $heading['attrs']['id'], 'offsetY' => (54 + $y) * 25.4 / 96], 'assetId' => $image['asset_id'], 'src' => $image['url'], 'lockAspectRatio' => true];
            }
        }
        $attendance = array_values(array_filter($snapshot['attendance'] ?? [], static fn (array $row): bool => (bool) ($row['present'] ?? false)));
        if (($this->settings['show_attendance_page'] ?? '1') === '1' && $attendance !== []) {
            $parts = [...$parts, ['type' => 'pageBreak'], $this->paragraph('บัญชีรายชื่อผู้เรียน', null, 'recordTitle', [['type' => 'bold']])];
            $cell = fn (string $value, bool $header = false): array => ['type' => $header ? 'tableHeader' : 'tableCell', 'content' => [$this->paragraph($value)]];
            $rows = [['type' => 'tableRow', 'content' => array_map(fn (string $label): array => $cell($label, true), ['ลำดับที่', 'รหัสนักศึกษา', 'ชื่อ - ชื่อสกุล', 'ลายมือชื่อ', 'หมายเหตุ'])]];
            foreach ($attendance as $index => $row) {
                $rows[] = ['type' => 'tableRow', 'content' => array_map($cell, [(string) ($index + 1), (string) ($row['student_id'] ?? ''), (string) ($row['full_name'] ?? ''), '', (string) ($row['learning_method'] ?? 'พบกลุ่ม')])];
            }
            $parts[] = ['type' => 'table', 'attrs' => ['columnSize' => '8,20,32,22,18'], 'content' => $rows];
        }

        return ['content' => ['type' => 'doc', 'content' => $parts], 'pageSettings' => $page, 'objects' => $objects];
    }

    private function paragraph(string $text, ?string $field = null, ?string $role = null, array $marks = []): array
    {
        return ['type' => 'paragraph', 'attrs' => ['id' => (string) Str::uuid(), 'field' => $field, 'role' => $role, 'spaceBefore' => 0, 'spaceAfter' => match ($role) {
            'recordTitle' => 6, 'signatureName', 'signaturePosition' => 0.75, default => 0
        }], ...($text === '' ? [] : ['content' => $this->inline($text, $field, $marks)])];
    }

    private function infoRow(array $segments): array
    {
        $cells = [];
        $used = 0;
        foreach ($segments as $segment) {
            [$label, $labelWidth, $value, $valueWidth] = $segment;
            $cells[] = $this->cell((string) $label, $labelWidth, false, true);
            $used += $labelWidth;
            if ($value !== null) {
                $cells[] = $this->cell((string) $value, $valueWidth, true, false, $segment[4] ?? null);
                $used += $valueWidth;
            }
        }
        if ($used < 99) {
            $cells[] = $this->cell('', 100 - $used, false, false);
        }

        return ['type' => 'recordInfoRow', 'attrs' => ['compact' => false], 'content' => $cells];
    }

    private function cell(string $text, float $width, bool $dotted, bool $label, ?string $field = null, string $unit = 'percent'): array
    {
        return ['type' => 'recordInfoCell', 'attrs' => ['width' => $width, 'unit' => $unit, 'dotted' => $dotted, 'label' => $label, 'nowrap' => true, 'field' => $field], ...($text === '' ? [] : ['content' => $this->inline($text, $field)])];
    }

    private function sectionTitle(string $text, string $field, ?int $signature = null): array
    {
        return ['type' => 'recordSectionTitle', 'attrs' => ['field' => $field, 'spaceBefore' => $signature ? 0 : 8, 'offsetX' => $signature ? $this->number("sig{$signature}_title_offset_x") : 0, 'offsetY' => $signature ? $this->number("sig{$signature}_title_offset_y") : 0], 'content' => $this->inline($text, $field, [['type' => 'bold']])];
    }

    private function body(string $text, ?string $field): array
    {
        $segments = preg_split('/(\[break\]|\[page-break\]|\*\*\*)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $content = [];
        foreach ($segments as $segment) {
            $node = preg_match('/^(\[break\]|\[page-break\]|\*\*\*)$/i', $segment) ? ['type' => 'pageBreak'] : $this->paragraph($segment);
            if ($segment !== '' && $node['type'] === 'paragraph') {
                $node['content'] = $this->inline($segment, $field);
            }
            $content[] = $node;
        }

        return ['type' => 'recordBody', 'attrs' => ['field' => $field, 'minLines' => 1, 'lineSpacing' => $this->number('pdf_line_spacing', 26), 'firstIndent' => $this->number('record_text_indent_px', 42), 'align' => $this->settings['record_text_align'] ?? 'justify', 'spaceAfter' => $this->number('record_paragraph_spacing_px')], 'content' => $content];
    }

    private function signature(int $index, string $teacher = ''): array
    {
        $prefix = "sig{$index}";
        $name = (string) ($this->settings[$prefix.'_name'] ?? '');
        if ($name === '') {
            $name = $index === 1 ? $teacher : '........................................................';
        }
        $position = (string) ($this->settings[$prefix.'_pos'] ?? ($index === 1 ? 'ครูผู้สอน' : ($index === 2 ? 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ' : 'ผู้อำนวยการสถานศึกษา')));
        $label = trim((string) ($this->settings[$prefix.($index === 1 ? '_sign_prefix' : '_sign_label')] ?? 'ลงชื่อ'));
        if ($label === '' || $label === 'ลงชื่อ') {
            $label = '(ลงชื่อ)';
        }
        $content = [
            ['type' => 'recordInfoRow', 'attrs' => ['compact' => true], 'content' => [
                $this->cell($label, 0, false, true, null, 'px'), $this->cell('', $this->number($prefix.'_dot_width', $index === 3 ? 200 : 180), true, false, null, 'px'), $this->cell($index === 1 ? (string) ($this->settings['sig1_sign_suffix'] ?? 'ผู้บันทึก') : '', 0, false, true, null, 'px'),
            ]],
            $this->paragraph('( '.$name.' )', $prefix.'_name', 'signatureName'),
            $this->paragraph(str_replace(['<br>', '<br/>', '<br />'], "\n", $position), $prefix.'_pos', 'signaturePosition'),
        ];
        foreach ([1 => 'name', 2 => 'pos'] as $part => $setting) {
            $content[$part]['attrs']['offsetX'] = $this->number($prefix.'_'.$setting.'_offset_x');
            $content[$part]['attrs']['offsetY'] = $this->number($prefix.'_'.$setting.'_offset_y');
        }
        $align = $this->settings[$prefix.'_align'] ?? ($index === 3 ? 'center' : 'flex-end');

        return ['type' => 'recordSignature', 'attrs' => ['index' => $index, 'align' => match ($align) {
            'center' => 'center', 'left', 'flex-start' => 'left', default => 'right'
        }, 'width' => 430, 'marginTop' => $this->number($prefix.'_mt', match ($index) {
            1 => 48, 2 => 40, default => 38
        }), 'offsetX' => $this->number($prefix.'_offset'), 'offsetY' => $this->number($prefix.'_offset_y')], 'content' => $content];
    }

    private function number(string $key, float $default = 0): float
    {
        return is_numeric($this->settings[$key] ?? null) ? (float) $this->settings[$key] : $default;
    }

    private function inline(string $text, ?string $field = null, array $marks = []): array
    {
        $html = $field ? DocumentRichText::render($text, $field, (string) ($this->settings['document_rich_text'] ?? '{}')) : nl2br(htmlspecialchars(DocumentRichText::normalize($text), ENT_QUOTES, 'UTF-8'), false);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $this->inlineChildren($dom->getElementsByTagName('body')->item(0), $marks);
    }

    private function inlineChildren(?DOMNode $parent, array $marks): array
    {
        $result = [];
        foreach ($parent?->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $value = $child->nodeValue ?? '';
                if ($child->previousSibling instanceof DOMElement && strtolower($child->previousSibling->tagName) === 'br' && str_starts_with($value, "\n")) {
                    $value = substr($value, 1);
                }
                if ($value !== '') {
                    $result[] = ['type' => 'text', 'text' => $value, ...($marks ? ['marks' => $marks] : [])];
                }
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    $result[] = ['type' => 'hardBreak'];

                    continue;
                }
                $next = $marks;
                $mark = match ($tag) {
                    'b', 'strong' => 'bold', 'i', 'em' => 'italic', 'u' => 'underline', 's', 'strike' => 'strike', default => null
                };
                if ($mark) {
                    $next[] = ['type' => $mark];
                }
                $style = $child->getAttribute('style');
                $textStyle = [];
                foreach (['font-family' => 'fontFamily', 'font-size' => 'fontSize', 'color' => 'color', 'background-color' => 'backgroundColor'] as $css => $attribute) {
                    if (preg_match('/(?:^|;)\s*'.preg_quote($css, '/').'\s*:\s*([^;]+)/i', $style, $match)) {
                        $textStyle[$attribute] = trim($match[1], " \t\"'");
                    }
                }
                if ($textStyle !== []) {
                    $next[] = ['type' => 'textStyle', 'attrs' => $textStyle];
                }
                if (preg_match('/font-weight\s*:\s*(?:bold|[7-9]00)/i', $style)) {
                    $next[] = ['type' => 'bold'];
                }
                if (preg_match('/font-style\s*:\s*italic/i', $style)) {
                    $next[] = ['type' => 'italic'];
                }
                $byType = [];
                foreach ($next as $value) {
                    $byType[$value['type']] = isset($byType[$value['type']]) && $value['type'] === 'textStyle' ? ['type' => 'textStyle', 'attrs' => [...$byType[$value['type']]['attrs'], ...$value['attrs']]] : $value;
                }
                $next = array_values($byType);
                $result = [...$result, ...$this->inlineChildren($child, $next)];
                if (in_array($tag, ['p', 'div', 'li'], true) && $child->nextSibling) {
                    $result[] = ['type' => 'hardBreak'];
                }
            }
        }

        return $result;
    }
}
