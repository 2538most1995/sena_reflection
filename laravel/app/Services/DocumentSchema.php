<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentSchema
{
    public const VERSION = 1;

    public const MAX_BYTES = 2_000_000;

    private const NODES = ['doc', 'paragraph', 'heading', 'text', 'hardBreak', 'horizontalRule', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'codeBlock', 'table', 'tableRowGroup', 'tableRow', 'tableHeader', 'tableCell', 'image', 'pageBreak', 'recordInfoRow', 'recordInfoCell', 'recordSectionTitle', 'recordBody', 'recordSignature', 'recordGroup'];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'textStyle', 'highlight', 'link', 'subscript', 'superscript'];

    private const FONTS = ['Sarabun', 'THSarabunNew', 'TH Sarabun New', 'Tahoma', 'Arial', 'IBM Plex Sans Thai', 'Anuphan'];

    public static function blankContent(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    public static function defaultPageSettings(): array
    {
        return ['size' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 15, 'right' => 20, 'bottom' => 15, 'left' => 20], 'header' => '', 'footer' => '', 'pageNumbers' => true, 'background' => '#ffffff', 'border' => 'none', 'theme' => 'blue', 'fontFamily' => 'Sarabun', 'fontSize' => 16, 'lineHeight' => 1.5];
    }

    /** @return array{title:string,content:array,pageSettings:array,objects:array,schemaVersion:int} */
    public function validate(array $payload, ?Document $document = null): array
    {
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            $this->reject('content', 'เอกสารมีขนาดใหญ่เกิน 2 MB');
        }
        $data = Validator::make($payload, [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'content' => ['sometimes', 'required', 'array'],
            'pageSettings' => ['sometimes', 'required', 'array'],
            'objects' => ['sometimes', 'present', 'array', 'max:300'],
            'schemaVersion' => ['sometimes', 'integer', Rule::in([self::VERSION])],
        ])->validate();
        $content = $data['content'] ?? $document?->content_json ?? self::blankContent();
        if (($content['type'] ?? null) !== 'doc') {
            $this->reject('content.type', 'เนื้อหาหลักต้องเป็นเอกสาร Tiptap');
        }
        $nodeCount = 0;
        $this->validateNode($content, $document, 'content', 0, $nodeCount);
        $settings = array_replace_recursive(self::defaultPageSettings(), $document?->page_settings_json ?? [], $data['pageSettings'] ?? []);
        $settings['header'] ??= '';
        $settings['footer'] ??= '';
        $this->validatePageSettings($settings);
        $objects = $data['objects'] ?? $document?->objects_json ?? [];
        if (! array_is_list($objects)) {
            $this->reject('objects', 'วัตถุต้องเป็นรายการ');
        }
        $ids = [];
        foreach ($objects as $index => $object) {
            if (! is_array($object)) {
                $this->reject("objects.{$index}", 'ข้อมูลวัตถุไม่ถูกต้อง');
            }
            $this->validateObject($object, $document, "objects.{$index}", $nodeCount);
            if (in_array($object['id'], $ids, true)) {
                $this->reject("objects.{$index}.id", 'รหัสวัตถุซ้ำกัน');
            }
            $ids[] = $object['id'];
        }

        return ['title' => $data['title'] ?? $document?->title ?? 'เอกสารไม่มีชื่อ', 'content' => $content, 'pageSettings' => $settings, 'objects' => $objects, 'schemaVersion' => self::VERSION];
    }

    private function validateNode(array $node, ?Document $document, string $path, int $depth, int &$count): void
    {
        if ($depth > 32 || ++$count > 10000) {
            $this->reject($path, 'โครงสร้างเอกสารซับซ้อนเกินไป');
        }
        if (array_diff(array_keys($node), ['type', 'attrs', 'content', 'text', 'marks']) !== []) {
            $this->reject($path, 'คุณสมบัติเนื้อหาไม่รองรับ');
        }
        $type = $node['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::NODES, true)) {
            $this->reject($path.'.type', 'ชนิดเนื้อหาไม่รองรับ');
        }
        if ($type === 'text' && (! is_string($node['text'] ?? null) || $node['text'] === '' || mb_strlen($node['text']) > 200000)) {
            $this->reject($path.'.text', 'ข้อความไม่ถูกต้องหรือยาวเกินไป');
        }
        if ($type !== 'text' && array_key_exists('text', $node)) {
            $this->reject($path.'.text', 'ช่องข้อความใช้ได้กับ text node เท่านั้น');
        }
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            $this->reject($path.'.attrs', 'คุณสมบัติเนื้อหาไม่ถูกต้อง');
        }
        $this->validateNodeAttrs($attrs, $type, $document, $path.'.attrs');
        $marks = $node['marks'] ?? [];
        if (! is_array($marks) || ! array_is_list($marks) || count($marks) > 12) {
            $this->reject($path.'.marks', 'รูปแบบตัวอักษรไม่ถูกต้อง');
        }
        foreach ($marks as $index => $mark) {
            $this->validateMark($mark, $path.'.marks.'.$index);
        }
        $children = $node['content'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            $this->reject($path.'.content', 'เนื้อหาย่อยต้องเป็นรายการ');
        }
        $allowedChildren = match ($type) {
            'text', 'hardBreak', 'image', 'horizontalRule', 'pageBreak' => [],
            'paragraph', 'heading', 'recordInfoCell', 'recordSectionTitle' => ['text', 'hardBreak', 'image'], 'codeBlock' => ['text'],
            'recordInfoRow' => ['recordInfoCell'],
            'table' => ['tableRow', 'tableRowGroup'], 'tableRowGroup' => ['tableRow'], 'tableRow' => ['tableCell', 'tableHeader'],
            'bulletList', 'orderedList' => ['listItem'], 'taskList' => ['taskItem'],
            default => ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'table', 'horizontalRule', 'pageBreak', 'recordInfoRow', 'recordSectionTitle', 'recordBody', 'recordSignature', 'recordGroup'],
        };
        if (in_array($type, ['doc', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'table', 'tableRowGroup', 'tableRow', 'tableHeader', 'tableCell'], true) && $children === []) {
            $this->reject($path.'.content', 'ส่วนนี้ต้องมีเนื้อหาย่อยอย่างน้อยหนึ่งรายการ');
        }
        if (in_array($type, ['listItem', 'taskItem'], true) && ($children[0]['type'] ?? null) !== 'paragraph') {
            $this->reject($path.'.content', 'รายการต้องเริ่มต้นด้วยย่อหน้า');
        }
        foreach ($children as $index => $child) {
            if (! is_array($child) || ! in_array($child['type'] ?? null, $allowedChildren, true)) {
                $this->reject($path.'.content.'.$index, 'โครงสร้างเนื้อหาย่อยไม่ถูกต้อง');
            }
            $this->validateNode($child, $document, $path.'.content.'.$index, $depth + 1, $count);
        }
    }

    private function validateNodeAttrs(array $attrs, string $type, ?Document $document, string $path): void
    {
        $rules = [
            'id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'textAlign' => ['nullable', Rule::in(['left', 'center', 'right', 'justify'])],
            'lineHeight' => ['nullable', 'numeric', 'between:0.7,4'],
            'spaceBefore' => ['nullable', 'numeric', 'between:0,120'], 'spaceAfter' => ['nullable', 'numeric', 'between:0,120'],
            'spacingBefore' => ['nullable', 'numeric', 'between:0,120'], 'spacingAfter' => ['nullable', 'numeric', 'between:0,120'],
            'indent' => ['nullable', 'numeric', 'between:0,120'], 'textIndent' => ['nullable', 'numeric', 'between:0,120'],
            'fontFamily' => ['nullable', Rule::in(self::FONTS)], 'fontSize' => ['nullable', $this->fontSizeRule()],
            'field' => ['nullable', Rule::in([...RecordDocumentOptions::CONTENT_KEYS, ...RecordDocumentOptions::RICH_TEXT_KEYS])],
            'role' => ['nullable', Rule::in(['recordTitle', 'signatureName', 'signaturePosition', 'signatureLine'])],
            'firstIndent' => ['nullable', 'numeric', 'between:0,160'], 'recordLine' => ['nullable', 'boolean'],
            'offsetX' => ['nullable', 'numeric', 'between:-2400,2400'], 'offsetY' => ['nullable', 'numeric', 'between:-2400,2400'],
        ];
        $rules = array_merge($rules, match ($type) {
            'heading' => ['level' => ['required', 'integer', 'between:1,6']],
            'orderedList' => ['start' => ['nullable', 'integer', 'between:1,100000'], 'type' => ['nullable', Rule::in(['1', 'a', 'A', 'i', 'I'])]],
            'taskItem' => ['checked' => ['nullable', 'boolean']],
            'codeBlock' => ['language' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_+.-]*$/']],
            'image' => ['src' => ['required', 'string', 'max:2000'], 'assetId' => ['nullable', 'uuid'], 'alt' => ['nullable', 'string', 'max:500'], 'title' => ['nullable', 'string', 'max:500'], 'width' => ['nullable', 'numeric', 'between:1,4000'], 'height' => ['nullable', 'numeric', 'between:1,4000']],
            'table' => ['columnSize' => ['nullable', 'string', 'max:2000', 'regex:/^(?:[0-9]+(?:\.[0-9]+)?(?:,[0-9]+(?:\.[0-9]+)?)*)?$/']],
            'tableCell', 'tableHeader' => ['colspan' => ['nullable', 'integer', 'between:1,100'], 'rowspan' => ['nullable', 'integer', 'between:1,100'], 'colwidth' => ['nullable', 'array', 'max:100'], 'colwidth.*' => ['integer', 'between:0,4000'], 'align' => ['nullable', Rule::in(['left', 'center', 'right', 'justify'])], 'backgroundColor' => ['nullable', $this->colorRule()]],
            'recordInfoRow' => ['compact' => ['nullable', 'boolean']],
            'recordInfoCell' => ['width' => ['nullable', 'numeric', 'between:0,1000'], 'unit' => ['nullable', Rule::in(['percent', 'px'])], 'dotted' => ['nullable', 'boolean'], 'label' => ['nullable', 'boolean'], 'nowrap' => ['nullable', 'boolean']],
            'recordSectionTitle' => ['spaceBefore' => ['nullable', 'numeric', 'between:0,300']],
            'recordBody' => ['minLines' => ['nullable', 'integer', 'between:0,100'], 'lineSpacing' => ['nullable', 'numeric', 'between:12,100'], 'align' => ['nullable', Rule::in(['left', 'center', 'right', 'justify'])], 'spaceAfter' => ['nullable', 'numeric', 'between:0,300']],
            'recordSignature' => ['index' => ['required', 'integer', 'between:1,3'], 'align' => ['nullable', Rule::in(['left', 'center', 'right'])], 'width' => ['nullable', 'numeric', 'between:80,1000'], 'marginTop' => ['nullable', 'numeric', 'between:0,500']],
            'recordGroup' => ['spaceBefore' => ['nullable', 'numeric', 'between:-120,500'], 'borderTop' => ['nullable', 'boolean']],
            default => [],
        });
        $this->validateAttrs($attrs, $rules, $path);
        if ($type === 'image') {
            $this->validateAsset($attrs['src'], $attrs['assetId'] ?? null, $document, $path.'.src');
        }
    }

    private function validateMark(mixed $mark, string $path): void
    {
        if (! is_array($mark) || ! in_array($mark['type'] ?? null, self::MARKS, true) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
            $this->reject($path, 'รูปแบบตัวอักษรไม่รองรับ');
        }
        $attrs = $mark['attrs'] ?? [];
        if (! is_array($attrs)) {
            $this->reject($path.'.attrs', 'คุณสมบัติตัวอักษรไม่ถูกต้อง');
        }
        $rules = match ($mark['type']) {
            'textStyle' => ['fontFamily' => ['nullable', Rule::in(self::FONTS)], 'fontSize' => ['nullable', $this->fontSizeRule()], 'color' => ['nullable', $this->colorRule()], 'backgroundColor' => ['nullable', $this->colorRule()], 'lineHeight' => ['nullable', 'numeric', 'between:0.7,4']],
            'highlight' => ['color' => ['nullable', $this->colorRule()]],
            'link' => ['href' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! $this->safeLink($value)) {
                    $fail('ที่อยู่ลิงก์ไม่ปลอดภัย');
                }
            }], 'target' => ['nullable', Rule::in(['_blank', '_self'])], 'rel' => ['nullable', 'string', 'regex:/^(?:noopener|noreferrer|nofollow|ugc|sponsored|\s)*$/'], 'class' => ['nullable', Rule::in([''])]],
            default => [],
        };
        $this->validateAttrs($attrs, $rules, $path.'.attrs');
    }

    private function validatePageSettings(array $settings): void
    {
        $rules = [
            'size' => ['required', Rule::in(['A4', 'A3', 'Letter'])], 'orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'margins' => ['required', 'array:top,right,bottom,left'],
            'margins.top' => ['required', 'numeric', 'between:0,100'], 'margins.right' => ['required', 'numeric', 'between:0,100'],
            'margins.bottom' => ['required', 'numeric', 'between:0,100'], 'margins.left' => ['required', 'numeric', 'between:0,100'],
            'header' => ['nullable', 'string', 'max:2000'], 'footer' => ['nullable', 'string', 'max:2000'], 'pageNumbers' => ['required', 'boolean'],
            'background' => ['required', $this->colorRule()], 'border' => ['required', $this->colorRule(true)],
            'theme' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'fontFamily' => ['required', Rule::in(self::FONTS)], 'fontSize' => ['required', 'numeric', 'between:8,96'], 'lineHeight' => ['required', 'numeric', 'between:0.7,4'],
            'template' => ['nullable', Rule::in(['weekly-record'])],
        ];
        $this->validateAttrs($settings, $rules, 'pageSettings');
        [$width, $height] = match ($settings['size']) {
            'A3' => [297, 420], 'Letter' => [215.9, 279.4], default => [210, 297]
        };
        if ($settings['orientation'] === 'landscape') {
            [$width, $height] = [$height, $width];
        }
        if ($settings['margins']['left'] + $settings['margins']['right'] >= $width - 10 || $settings['margins']['top'] + $settings['margins']['bottom'] >= $height - 10) {
            $this->reject('pageSettings.margins', 'ระยะขอบเหลือพื้นที่สำหรับเอกสารน้อยเกินไป');
        }
    }

    private function validateObject(array $object, ?Document $document, string $path, int &$nodeCount): void
    {
        $rules = [
            'id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'], 'type' => ['required', Rule::in(['image', 'textbox', 'shape', 'line'])],
            'page' => ['required', 'integer', 'between:1,1000'], 'x' => ['required', 'numeric', 'between:-1000,2000'], 'y' => ['required', 'numeric', 'between:-1000,2000'],
            'width' => ['required', 'numeric', 'between:0.1,2000'], 'height' => ['required', 'numeric', 'between:0.1,2000'],
            'rotation' => ['required', 'numeric', 'between:-360,360'], 'zIndex' => ['required', 'integer', 'between:-10000,10000'], 'opacity' => ['required', 'numeric', 'between:0,1'],
            'wrap' => ['required', Rule::in(['inline', 'square', 'top-bottom'])], 'anchor' => ['required', 'array:kind,page,id,offsetY'],
            'anchor.kind' => ['required', Rule::in(['page', 'paragraph'])], 'anchor.page' => ['nullable', 'integer', 'between:1,1000'], 'anchor.id' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'anchor.offsetY' => ['nullable', 'numeric', 'between:-2000,2000'],
            'assetId' => ['nullable', 'uuid'], 'src' => ['nullable', 'string', 'max:2000'], 'alt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'array'], 'text' => ['nullable', 'string', 'max:20000'], 'shape' => ['nullable', Rule::in(['rect', 'rectangle', 'ellipse', 'circle', 'triangle'])],
            'fill' => ['nullable', $this->colorRule(true)], 'border' => ['nullable', $this->colorRule(true)], 'borderColor' => ['nullable', $this->colorRule(true)], 'borderWidth' => ['nullable', 'numeric', 'between:0,20'],
            'padding' => ['nullable', 'numeric', 'between:0,30'], 'lockAspectRatio' => ['nullable', 'boolean'], 'locked' => ['nullable', 'boolean'], 'borderRadius' => ['nullable', 'numeric', 'between:0,100'],
        ];
        $this->validateAttrs($object, $rules, $path);
        if ($object['anchor']['kind'] === 'page' && ! isset($object['anchor']['page'])) {
            $this->reject($path.'.anchor.page', 'กรุณาระบุหน้าที่ผูกวัตถุ');
        }
        if ($object['anchor']['kind'] === 'paragraph' && empty($object['anchor']['id'])) {
            $this->reject($path.'.anchor.id', 'กรุณาระบุย่อหน้าที่ผูกวัตถุ');
        }
        if ($object['type'] === 'image') {
            $this->validateAsset((string) ($object['src'] ?? ''), $object['assetId'] ?? null, $document, $path.'.src');
        }
        if (isset($object['content'])) {
            if (($object['content']['type'] ?? null) !== 'doc') {
                $this->reject($path.'.content', 'ข้อความในกล่องต้องเป็นเอกสาร Tiptap');
            }
            $this->validateNode($object['content'], $document, $path.'.content', 0, $nodeCount);
        }
    }

    private function validateAsset(string $url, ?string $id, ?Document $document, string $path): void
    {
        if ($document !== null) {
            $document->loadMissing('assets');
            foreach ($document->assets as $asset) {
                $expected = route('documents.assets.show', ['document' => $document, 'asset' => $asset]);
                if (($id === null || $id === $asset->id) && ($url === $expected || $url === parse_url($expected, PHP_URL_PATH))) {
                    return;
                }
            }
        }
        $this->reject($path, 'รูปภาพต้องเป็นไฟล์ของเอกสารนี้');
    }

    private function validateAttrs(array $attrs, array $rules, string $path): void
    {
        $allowedKeys = array_filter(array_keys($rules), static fn (string $key): bool => ! str_contains($key, '.'));
        foreach (array_keys($attrs) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $this->reject($path.'.'.$key, 'คุณสมบัตินี้ไม่รองรับ');
            }
        }
        $validator = Validator::make($attrs, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[$path.'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function colorRule(bool $allowNone = false): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($allowNone): void {
            $valid = is_string($value) && (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1 || ($allowNone && in_array($value, ['none', 'transparent'], true)));
            if (! $valid && is_string($value) && preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0|1|0?\.\d+))?\s*\)$/i', $value, $channels) === 1) {
                $valid = max((int) $channels[1], (int) $channels[2], (int) $channels[3]) <= 255;
            }
            if (! $valid) {
                $fail('ค่าสีต้องเป็นรหัสสีที่ปลอดภัย');
            }
        };
    }

    private function fontSizeRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_scalar($value) || preg_match('/^(\d+(?:\.\d+)?)(pt|px)?$/', (string) $value, $matches) !== 1 || (float) $matches[1] < 8 || (float) $matches[1] > 128) {
                $fail('ขนาดตัวอักษรไม่ถูกต้อง');
            }
        };
    }

    private function safeLink(string $url): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url) === 1 || str_starts_with($url, '//')) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) || ($scheme === '' && preg_match('~^(?:/[^/]|#[a-zA-Z0-9_-])~', $url) === 1);
    }

    private function reject(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
