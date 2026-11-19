<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TeachingGroups
{
    public static function fingerprintAttributes(array $attributes): array
    {
        if (($attributes['class_groups'] ?? null) === null) {
            unset($attributes['class_groups']);
        }

        return $attributes;
    }

    /** @return list<array{group_code: string, class_name: string}> */
    public static function selected(array $entry): array
    {
        if (! empty($entry['class_groups']) && is_array($entry['class_groups'])) {
            return array_values(array_map(static fn (array $group): array => [
                'group_code' => trim((string) ($group['group_code'] ?? '')),
                'class_name' => trim((string) ($group['class_name'] ?? '')),
            ], $entry['class_groups']));
        }
        $code = trim((string) ($entry['class_group_code'] ?? ''));
        $name = trim((string) ($entry['class_name'] ?? ''));

        return $code !== '' || $name !== '' ? [['group_code' => $code, 'class_name' => $name]] : [];
    }

    /** @return list<string> */
    public static function codes(array $entry): array
    {
        return array_values(array_filter(array_column(self::selected($entry), 'group_code'), static fn (string $code): bool => $code !== ''));
    }

    public static function overlap(array $left, array $right): bool
    {
        foreach (self::selected($left) as $first) {
            foreach (self::selected($right) as $second) {
                if ($first['group_code'] !== '' && $second['group_code'] !== '') {
                    if (mb_strtolower($first['group_code']) === mb_strtolower($second['group_code'])) {
                        return true;
                    }
                } elseif ($first['class_name'] !== '' && mb_strtolower($first['class_name']) === mb_strtolower($second['class_name'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function normalize(array $entry, string $path): array
    {
        if (empty($entry['class_groups'])) {
            return $entry;
        }
        $groups = self::selected($entry);
        $codes = array_column($groups, 'group_code');
        if (count($groups) > 20 || in_array('', $codes, true) || count(array_unique($codes)) !== count($codes)) {
            throw ValidationException::withMessages([$path.'.class_groups' => 'เลือกกลุ่มเรียนที่ไม่ซ้ำกันได้ไม่เกิน 20 กลุ่ม']);
        }
        $name = implode(' / ', array_unique(array_map(static fn (array $group): string => $group['class_name'] ?: $group['group_code'], $groups)));
        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([$path.'.class_groups' => 'ชื่อกลุ่มเรียนรวมยาวเกิน 255 ตัวอักษร กรุณาลดจำนวนกลุ่ม']);
        }

        return [...$entry, 'class_groups' => $groups, 'class_group_code' => $codes[0], 'class_name' => $name];
    }
}
