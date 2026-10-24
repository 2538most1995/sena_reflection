<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordAttendance extends Model
{
    public $timestamps = false;

    protected $table = 'record_attendance';

    protected $guarded = [];

    private static ?bool $requiresStringId = null;

    /**
     * Insert attendance rows adaptively handling both auto-increment integer ID
     * and legacy/custom string IDs.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function insertAdaptively(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        if (self::requiresStringId()) {
            $withIds = array_map(fn (array $r): array => [
                'id' => $r['id'] ?? ('a'.substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 19)),
                ...$r,
            ], $rows);
            static::query()->insert($withIds);

            return;
        }

        try {
            static::query()->insert($rows);
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, "Field 'id' doesn't have a default value")
                || str_contains($msg, "Column 'id' cannot be null")
                || str_contains($msg, 'NOT NULL constraint failed: record_attendance.id')) {
                self::$requiresStringId = true;
                $withIds = array_map(fn (array $r): array => [
                    'id' => $r['id'] ?? ('a'.substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 19)),
                    ...$r,
                ], $rows);
                static::query()->insert($withIds);
            } else {
                throw $e;
            }
        }
    }

    public static function requiresStringId(): bool
    {
        if (self::$requiresStringId !== null && ! app()->runningUnitTests()) {
            return self::$requiresStringId;
        }

        try {
            $connection = (new static)->getConnection();
            if ($connection->getDriverName() === 'mysql') {
                $column = $connection->selectOne("SHOW COLUMNS FROM `record_attendance` LIKE 'id'");
                if ($column) {
                    $column = (object) array_change_key_case((array) $column, CASE_LOWER);
                    $isAuto = str_contains(strtolower((string) ($column->extra ?? '')), 'auto_increment');
                    if ($isAuto) {
                        return self::$requiresStringId = false;
                    }
                    $type = strtolower((string) ($column->type ?? ''));
                    $isNumeric = preg_match('/^(int|bigint|smallint|tinyint|mediumint)/i', $type) === 1;

                    return self::$requiresStringId = ! $isNumeric;
                }
            }

            return self::$requiresStringId = false;
        } catch (\Throwable) {
            return self::$requiresStringId = false;
        }
    }

    public static function flushKeyTypeCache(): void
    {
        self::$requiresStringId = null;
    }
}
