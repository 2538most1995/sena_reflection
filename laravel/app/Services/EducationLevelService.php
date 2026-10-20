<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EducationLevelService
{
    public const NAMES = [
        '1' => 'ประถมศึกษา',
        '2' => 'มัธยมศึกษาตอนต้น',
        '3' => 'มัธยมศึกษาตอนปลาย',
    ];

    /** @return list<string> */
    public static function enabledCodes(array $payload): array
    {
        $codes = array_key_exists('enabled_level_codes', $payload) ? $payload['enabled_level_codes'] : ['1', '2', '3'];
        Validator::make(['payload' => ['enabled_level_codes' => $codes]], [
            'payload.enabled_level_codes' => ['required', 'array', 'min:1', 'max:3'],
            'payload.enabled_level_codes.*' => ['required', 'distinct', Rule::in(['1', '2', '3'])],
        ], ['payload.enabled_level_codes.required' => 'กรุณาเลือกระดับชั้นที่สอนอย่างน้อย 1 ระดับ'])->validate();

        return array_values(array_intersect(['1', '2', '3'], array_map('strval', $codes)));
    }

    public function code(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));
        $normalized = preg_replace('/[\s._-]+/u', '', $normalized) ?? $normalized;

        return match ($normalized) {
            '1', 'primary', 'primaryschool', 'ประถม', 'ประถมศึกษา' => '1',
            '2', 'lowersecondary', 'lower', 'มต้น', 'มัธยมต้น', 'มัธยมศึกษาตอนต้น' => '2',
            '3', 'uppersecondary', 'upper', 'มปลาย', 'มัธยมปลาย', 'มัธยมศึกษาตอนปลาย' => '3',
            default => null,
        };
    }

    public function name(mixed $value): ?string
    {
        $code = $this->code($value);

        return $code === null ? null : self::NAMES[$code];
    }

    /** @return array{level: string, level_code: string, level_name: string}|null */
    public function fields(mixed $value): ?array
    {
        $code = $this->code($value);
        if ($code === null) {
            return null;
        }

        return ['level' => $code, 'level_code' => $code, 'level_name' => self::NAMES[$code]];
    }
}
