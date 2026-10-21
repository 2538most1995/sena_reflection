<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordDocumentOptions
{
    public const SETTING_KEYS = [
        'image_1_crop_x', 'image_1_crop_y', 'image_2_crop_x', 'image_2_crop_y',
        'image_3_crop_x', 'image_3_crop_y', 'image_4_crop_x', 'image_4_crop_y',
        'paper_size', 'paper_orientation',
        'sig1_name', 'sig1_pos', 'sig1_align', 'sig1_offset', 'sig1_offset_y',
        'sig2_title', 'sig2_name', 'sig2_pos', 'sig2_align', 'sig2_offset', 'sig2_offset_y',
        'sig3_title', 'sig3_name', 'sig3_pos', 'sig3_align', 'sig3_offset', 'sig3_offset_y',
        'record_section1_title', 'record_section2_title', 'record_section3_title',
        'pdf_font_size_pt', 'pdf_line_spacing',
        'sig1_mt', 'sig2_mt', 'sig3_mt', 'sig2_block_offset_y', 'sig3_block_offset_y',
        'record_paragraph_spacing_px', 'record_text_indent_px', 'record_text_align',
        'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px',
        'show_image_page', 'show_attendance_page', 'image_layout_mode',
        'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
        'image_layout_by_id',
        'image_1_width_px', 'image_1_height_px', 'image_1_offset_x', 'image_1_offset_y', 'image_1_x_px', 'image_1_y_px', 'image_1_object_fit', 'image_1_rotation_deg', 'image_1_lock_aspect',
        'image_2_width_px', 'image_2_height_px', 'image_2_offset_x', 'image_2_offset_y', 'image_2_x_px', 'image_2_y_px', 'image_2_object_fit', 'image_2_rotation_deg', 'image_2_lock_aspect',
        'image_3_width_px', 'image_3_height_px', 'image_3_offset_x', 'image_3_offset_y', 'image_3_x_px', 'image_3_y_px', 'image_3_object_fit', 'image_3_rotation_deg', 'image_3_lock_aspect',
        'image_4_width_px', 'image_4_height_px', 'image_4_offset_x', 'image_4_offset_y', 'image_4_x_px', 'image_4_y_px', 'image_4_object_fit', 'image_4_rotation_deg', 'image_4_lock_aspect',
    ];

    public const CONTENT_KEYS = [
        'record_subject', 'record_subject_code', 'record_class_group_code', 'record_class_name',
        'record_result', 'record_problem', 'record_suggestion', 'record_supervisor_comment',
    ];

    private const DEFAULTS = [
        'image_1_crop_x' => '50', 'image_1_crop_y' => '50', 'image_2_crop_x' => '50', 'image_2_crop_y' => '50',
        'image_3_crop_x' => '50', 'image_3_crop_y' => '50', 'image_4_crop_x' => '50', 'image_4_crop_y' => '50',
        'paper_size' => 'A4', 'paper_orientation' => 'portrait',
        'sig1_name' => '', 'sig1_pos' => 'ครูผู้สอน', 'sig1_align' => 'flex-end', 'sig1_offset' => '0', 'sig1_offset_y' => '0',
        'sig2_title' => 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', 'sig2_name' => '', 'sig2_pos' => 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', 'sig2_align' => 'flex-end', 'sig2_offset' => '0', 'sig2_offset_y' => '0',
        'sig3_title' => 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา', 'sig3_name' => '', 'sig3_pos' => 'ผู้อำนวยการสถานศึกษา', 'sig3_align' => 'center', 'sig3_offset' => '0', 'sig3_offset_y' => '0',
        'record_section1_title' => '๑. ผลการจัดการเรียนการสอน', 'record_section2_title' => '๒. สภาพปัญหาและอุปสรรค', 'record_section3_title' => '๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา',
        'pdf_font_size_pt' => '16', 'pdf_line_spacing' => '26',
        'sig1_mt' => '16', 'sig2_mt' => '16', 'sig3_mt' => '16', 'sig2_block_offset_y' => '0', 'sig3_block_offset_y' => '0',
        'record_paragraph_spacing_px' => '0', 'record_text_indent_px' => '42', 'record_text_align' => 'justify',
        'pdf_margin_top_px' => '57', 'pdf_margin_right_px' => '76', 'pdf_margin_bottom_px' => '38', 'pdf_margin_left_px' => '94',
        'show_image_page' => '1', 'show_attendance_page' => '1', 'image_layout_mode' => 'free',
        'image_width_px' => '300', 'image_height_px' => '225', 'image_offset_x' => '0', 'image_offset_y' => '0', 'image_gap_px' => '16',
        'image_layout_by_id' => '{}',
        'image_1_width_px' => '300', 'image_1_height_px' => '225', 'image_1_offset_x' => '0', 'image_1_offset_y' => '0', 'image_1_x_px' => '0', 'image_1_y_px' => '8', 'image_1_object_fit' => 'contain', 'image_1_rotation_deg' => '0', 'image_1_lock_aspect' => '1',
        'image_2_width_px' => '300', 'image_2_height_px' => '225', 'image_2_offset_x' => '0', 'image_2_offset_y' => '0', 'image_2_x_px' => '316', 'image_2_y_px' => '8', 'image_2_object_fit' => 'contain', 'image_2_rotation_deg' => '0', 'image_2_lock_aspect' => '1',
        'image_3_width_px' => '300', 'image_3_height_px' => '225', 'image_3_offset_x' => '0', 'image_3_offset_y' => '0', 'image_3_x_px' => '0', 'image_3_y_px' => '249', 'image_3_object_fit' => 'contain', 'image_3_rotation_deg' => '0', 'image_3_lock_aspect' => '1',
        'image_4_width_px' => '300', 'image_4_height_px' => '225', 'image_4_offset_x' => '0', 'image_4_offset_y' => '0', 'image_4_x_px' => '316', 'image_4_y_px' => '249', 'image_4_object_fit' => 'contain', 'image_4_rotation_deg' => '0', 'image_4_lock_aspect' => '1',
    ];

    private const NUMERIC_RANGES = [
        'image_1_crop_x' => [0, 100], 'image_1_crop_y' => [0, 100], 'image_2_crop_x' => [0, 100], 'image_2_crop_y' => [0, 100],
        'image_3_crop_x' => [0, 100], 'image_3_crop_y' => [0, 100], 'image_4_crop_x' => [0, 100], 'image_4_crop_y' => [0, 100],
        'sig1_offset' => [-400, 400], 'sig1_offset_y' => [-400, 400],
        'sig2_offset' => [-400, 400], 'sig2_offset_y' => [-400, 400],
        'sig3_offset' => [-400, 400], 'sig3_offset_y' => [-400, 400],
        'pdf_font_size_pt' => [10, 20], 'pdf_line_spacing' => [20, 40],
        'sig1_mt' => [0, 240], 'sig2_mt' => [0, 240], 'sig3_mt' => [0, 240],
        'sig2_block_offset_y' => [-120, 240], 'sig3_block_offset_y' => [-120, 240],
        'record_paragraph_spacing_px' => [0, 80], 'record_text_indent_px' => [0, 160],
        'pdf_margin_top_px' => [20, 140], 'pdf_margin_right_px' => [20, 160],
        'pdf_margin_bottom_px' => [20, 140], 'pdf_margin_left_px' => [20, 160],
        'image_width_px' => [180, 620], 'image_height_px' => [120, 560],
        'image_offset_x' => [-220, 220], 'image_offset_y' => [-220, 220], 'image_gap_px' => [0, 72],
        'image_1_width_px' => [10, 2400], 'image_1_height_px' => [10, 2400], 'image_1_offset_x' => [-220, 220], 'image_1_offset_y' => [-220, 220], 'image_1_x_px' => [-2400, 2400], 'image_1_y_px' => [-2400, 2400],
        'image_2_width_px' => [10, 2400], 'image_2_height_px' => [10, 2400], 'image_2_offset_x' => [-220, 220], 'image_2_offset_y' => [-220, 220], 'image_2_x_px' => [-2400, 2400], 'image_2_y_px' => [-2400, 2400],
        'image_3_width_px' => [10, 2400], 'image_3_height_px' => [10, 2400], 'image_3_offset_x' => [-220, 220], 'image_3_offset_y' => [-220, 220], 'image_3_x_px' => [-2400, 2400], 'image_3_y_px' => [-2400, 2400],
        'image_4_width_px' => [10, 2400], 'image_4_height_px' => [10, 2400], 'image_4_offset_x' => [-220, 220], 'image_4_offset_y' => [-220, 220], 'image_4_x_px' => [-2400, 2400], 'image_4_y_px' => [-2400, 2400],
    ];

    /** @return array<string, string> */
    public function settings(array $overrides = [], array $imageIds = [], ?string $tambon = null): array
    {
        $global = array_intersect_key($this->legacySettings($tambon, Auth::user()), array_flip(self::SETTING_KEYS));

        // The global PDF screen still uses its table layout. A new per-record
        // free canvas starts from a predictable 300x225 arrangement instead of
        // inheriting old table cell dimensions that can overlap on load.
        unset($global['image_layout_mode'], $global['image_layout_by_id']);
        for ($index = 1; $index <= 4; $index++) {
            foreach (['width_px', 'height_px', 'x_px', 'y_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'] as $part) {
                $key = "image_{$index}_{$part}";
                if (! array_key_exists($key, $overrides)) {
                    unset($global[$key]);
                }
            }
        }

        // Carry old per-record offsets into the new absolute canvas once. New
        // payloads already contain x/y and therefore skip this compatibility path.
        $defaults = self::DEFAULTS;
        for ($index = 1; $index <= 4; $index++) {
            $xKey = "image_{$index}_x_px";
            $yKey = "image_{$index}_y_px";
            if (! array_key_exists($xKey, $overrides) && array_key_exists("image_{$index}_offset_x", $overrides)) {
                $defaults[$xKey] = (string) ((int) $defaults[$xKey] + (int) $overrides["image_{$index}_offset_x"]);
            }
            if (! array_key_exists($yKey, $overrides) && array_key_exists("image_{$index}_offset_y", $overrides)) {
                $defaults[$yKey] = (string) ((int) $defaults[$yKey] + (int) $overrides["image_{$index}_offset_y"]);
            }
        }

        $settings = collect(array_merge($defaults, $global, collect($overrides)->only(self::SETTING_KEYS)->all()))
            ->map(fn ($value) => (string) ($value ?? ''))
            ->all();

        if ($imageIds === []) {
            return $settings;
        }

        return $this->applyStableImageLayout($settings, $imageIds);
    }

    /** @return array<string, string> */
    public function content(TeachingRecord $record, array $overrides = []): array
    {
        $defaults = [
            'record_subject' => (string) $record->subject,
            'record_subject_code' => (string) ($record->subject_code ?? ''),
            'record_class_group_code' => (string) ($record->class_group_code ?? ''),
            'record_class_name' => (string) ($record->class_name ?? ''),
            'record_result' => (string) ($record->result ?? ''),
            'record_problem' => (string) ($record->problem ?? ''),
            'record_suggestion' => (string) ($record->suggestion ?? ''),
            'record_supervisor_comment' => (string) ($record->supervisor_comment ?? ''),
        ];

        return collect(array_merge($defaults, collect($overrides)->only(self::CONTENT_KEYS)->all()))
            ->map(fn ($value) => (string) ($value ?? ''))
            ->all();
    }

    /** @return array{settings: array<string, string>, content: array<string, string>} */
    public function validate(TeachingRecord $record, array $payload): array
    {
        $validator = Validator::make($payload, [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:10000'],
            'content' => ['required', 'array'],
            'content.*' => ['nullable', 'string', 'max:10000'],
        ]);
        $validated = $validator->validate();
        $record->loadMissing('images:id,record_id,file_path');
        $imageIds = $record->images->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $validated['settings']['image_layout_by_id'] = $this->stableImageLayoutFromSlots($validated['settings'], $imageIds);
        $settings = $this->settings($validated['settings'], $imageIds, $record->tambon);
        $content = $this->content($record, $validated['content']);

        Validator::make($settings, [
            'paper_size' => ['required', Rule::in(['A4'])],
            'paper_orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'record_text_align' => ['required', Rule::in(['left', 'justify', 'center', 'right'])],
            'sig1_align' => ['required', Rule::in(['flex-start', 'center', 'flex-end'])],
            'sig2_align' => ['required', Rule::in(['flex-start', 'center', 'flex-end'])],
            'sig3_align' => ['required', Rule::in(['flex-start', 'center', 'flex-end'])],
            'show_image_page' => ['required', Rule::in(['0', '1'])],
            'show_attendance_page' => ['required', Rule::in(['0', '1'])],
            'image_layout_mode' => ['required', Rule::in(['free', 'grid'])],
            'image_1_object_fit' => ['required', Rule::in(['contain', 'cover', 'fill'])],
            'image_2_object_fit' => ['required', Rule::in(['contain', 'cover', 'fill'])],
            'image_3_object_fit' => ['required', Rule::in(['contain', 'cover', 'fill'])],
            'image_4_object_fit' => ['required', Rule::in(['contain', 'cover', 'fill'])],
            'image_1_rotation_deg' => ['required', Rule::in(['0', '90', '180', '270'])],
            'image_2_rotation_deg' => ['required', Rule::in(['0', '90', '180', '270'])],
            'image_3_rotation_deg' => ['required', Rule::in(['0', '90', '180', '270'])],
            'image_4_rotation_deg' => ['required', Rule::in(['0', '90', '180', '270'])],
            'image_1_lock_aspect' => ['required', Rule::in(['0', '1'])],
            'image_2_lock_aspect' => ['required', Rule::in(['0', '1'])],
            'image_3_lock_aspect' => ['required', Rule::in(['0', '1'])],
            'image_4_lock_aspect' => ['required', Rule::in(['0', '1'])],
        ])->validate();

        $this->validateStableImageLayout($settings['image_layout_by_id']);

        foreach (self::NUMERIC_RANGES as $key => [$minimum, $maximum]) {
            Validator::make($settings, [
                $key => ['required', 'integer', "between:{$minimum},{$maximum}"],
            ])->validate();
        }

        Validator::make($content, [
            'record_subject' => ['required', 'string', 'max:150'],
            'record_subject_code' => ['nullable', 'string', 'max:50'],
            'record_class_group_code' => ['nullable', 'string', 'max:50'],
            'record_class_name' => ['nullable', 'string', 'max:255'],
            'record_result' => ['nullable', 'string', 'max:10000'],
            'record_problem' => ['nullable', 'string', 'max:10000'],
            'record_suggestion' => ['nullable', 'string', 'max:10000'],
            'record_supervisor_comment' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return compact('settings', 'content');
    }

    /** @return array<string, string> */
    public function legacySettings(?string $tambon = null, ?User $user = null): array
    {
        $settings = Setting::query()->pluck('setting_value', 'setting_key')->all();
        $tambon ??= $user?->tambon;
        $candidateKeys = array_unique(array_merge(self::SETTING_KEYS, array_map(static fn (string $key): string => preg_replace('/^(tambon|user):[^:]+:/', '', $key), array_keys($settings))));
        $scopedKeys = array_filter($candidateKeys, static fn (string $key): bool => preg_match('/^(sig1_|sig[23]_offset|font_size_offset$|pdf_|signature_font_size_pt$|attendance_font_size_pt$|image_)/', $key) === 1);
        foreach ($scopedKeys as $key) {
            if ($tambon && array_key_exists("tambon:{$tambon}:{$key}", $settings)) {
                $settings[$key] = $settings["tambon:{$tambon}:{$key}"];
            }
            if ($user && ! $user->isDistrict() && array_key_exists("user:{$user->id}:{$key}", $settings)) {
                $settings[$key] = $settings["user:{$user->id}:{$key}"];
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<int, string>  $imageIds
     * @return array<string, string>
     */
    private function applyStableImageLayout(array $settings, array $imageIds): array
    {
        $decoded = json_decode($settings['image_layout_by_id'] ?? '{}', true);
        $storedLayouts = is_array($decoded) ? $decoded : [];
        // Older integer casts collapsed string image ids into one invalid key.
        // Keep the original positional slots when repairing that exact map.
        if (count($storedLayouts) === 1 && array_key_exists(0, $storedLayouts)) {
            $storedLayouts = [];
        }
        $stableLayouts = [];
        $reservedLayouts = array_filter(array_intersect_key($storedLayouts, array_fill_keys($imageIds, true)), 'is_array');
        $parts = ['width_px', 'height_px', 'x_px', 'y_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'];

        foreach (array_values(array_slice($imageIds, 0, 4)) as $index => $imageId) {
            $slot = $index + 1;
            $stored = is_array($storedLayouts[$imageId] ?? null) ? $storedLayouts[$imageId] : [];
            if ($stored === [] && $storedLayouts !== []) {
                $stored = $this->newImageLayout($settings, $reservedLayouts);
                $reservedLayouts[$imageId] = $stored;
            }
            $layout = [];
            foreach ($parts as $part) {
                $positionalKey = "image_{$slot}_{$part}";
                $layout[$part] = (string) ($stored[$part] ?? $settings[$positionalKey]);
                $settings[$positionalKey] = $layout[$part];
            }
            $stableLayouts[$imageId] = $layout;
        }

        $settings['image_layout_by_id'] = json_encode($stableLayouts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $settings;
    }

    /**
     * A newly attached image must not inherit the former occupant's slot. Keep
     * existing images fixed and start the new image in the first vacant area.
     *
     * @param  array<string, string>  $settings
     * @param  array<string, array<string, string>>  $reserved
     * @return array<string, string>
     */
    private function newImageLayout(array $settings, array $reserved): array
    {
        $layout = [];
        foreach (['width_px', 'height_px', 'x_px', 'y_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'] as $part) {
            $layout[$part] = self::DEFAULTS["image_1_{$part}"];
        }
        $landscape = $settings['paper_orientation'] === 'landscape';
        $canvasWidth = max(120, ($landscape ? 1123 : 794) - (int) $settings['pdf_margin_left_px'] - (int) $settings['pdf_margin_right_px']);
        $canvasHeight = max(160, ($landscape ? 794 : 1123) - (int) $settings['pdf_margin_top_px'] - (int) $settings['pdf_margin_bottom_px'] - 54);
        $width = min((int) $layout['width_px'], $canvasWidth);
        $height = min((int) $layout['height_px'], $canvasHeight);
        $layout['width_px'] = (string) $width;
        $layout['height_px'] = (string) $height;
        $xs = [0];
        $ys = [8];
        foreach ($reserved as $existing) {
            $xs[] = (int) ($existing['x_px'] ?? 0) + (int) ($existing['width_px'] ?? 300) + 16;
            $ys[] = (int) ($existing['y_px'] ?? 8) + (int) ($existing['height_px'] ?? 225) + 16;
        }
        sort($xs);
        sort($ys);
        foreach (array_unique($ys) as $y) {
            foreach (array_unique($xs) as $x) {
                if ($x + $width > $canvasWidth || $y + $height > $canvasHeight) {
                    continue;
                }
                $overlaps = collect($reserved)->contains(fn (array $existing): bool => $x < (int) ($existing['x_px'] ?? 0) + (int) ($existing['width_px'] ?? 300)
                    && $x + $width > (int) ($existing['x_px'] ?? 0)
                    && $y < (int) ($existing['y_px'] ?? 8) + (int) ($existing['height_px'] ?? 225)
                    && $y + $height > (int) ($existing['y_px'] ?? 8)
                );
                if (! $overlaps) {
                    return [...$layout, 'x_px' => (string) $x, 'y_px' => (string) $y];
                }
            }
        }

        return $layout;
    }

    private function validateStableImageLayout(string $encoded): void
    {
        $layouts = json_decode($encoded, true);
        Validator::make(['layouts' => $layouts], [
            'layouts' => ['array', 'max:4'],
            'layouts.*' => ['required', 'array'],
            'layouts.*.width_px' => ['required', 'integer', 'between:10,2400'],
            'layouts.*.height_px' => ['required', 'integer', 'between:10,2400'],
            'layouts.*.x_px' => ['required', 'integer', 'between:-2400,2400'],
            'layouts.*.y_px' => ['required', 'integer', 'between:-2400,2400'],
            'layouts.*.object_fit' => ['required', Rule::in(['contain', 'cover', 'fill'])],
            'layouts.*.rotation_deg' => ['required', Rule::in(['0', '90', '180', '270'])],
            'layouts.*.lock_aspect' => ['required', Rule::in(['0', '1'])],
            'layouts.*.crop_x' => ['required', 'integer', 'between:0,100'],
            'layouts.*.crop_y' => ['required', 'integer', 'between:0,100'],
        ])->validate();
    }

    /**
     * Persist editor slot changes by stable image id. The editor receives slots
     * projected from this map, while saved/reordered images continue to follow
     * their id instead of inheriting another image's geometry.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $imageIds
     */
    private function stableImageLayoutFromSlots(array $settings, array $imageIds): string
    {
        $decoded = json_decode((string) ($settings['image_layout_by_id'] ?? '{}'), true);
        $layouts = is_array($decoded) ? $decoded : [];
        $parts = ['width_px', 'height_px', 'x_px', 'y_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'];
        $stable = [];

        foreach (array_values(array_slice($imageIds, 0, 4)) as $index => $imageId) {
            $slot = $index + 1;
            $stored = is_array($layouts[$imageId] ?? null) ? $layouts[$imageId] : [];
            foreach ($parts as $part) {
                $key = "image_{$slot}_{$part}";
                $stored[$part] = (string) ($settings[$key] ?? $stored[$part] ?? self::DEFAULTS[$key]);
            }
            $stable[$imageId] = $stored;
        }

        return json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
