<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecordAttendance extends Model
{
    public $timestamps = false;

    protected $table = 'record_attendance';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @param list<array<string, mixed>> $rows */
    public static function insertAdaptively(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (self::requiresStringId()) {
            $rows = array_map(static fn (array $row): array => [
                ...$row,
                'id' => $row['id'] ?? ('a'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19)),
            ], $rows);
        }
        static::query()->insert($rows);
    }

    public static function requiresStringId(): bool
    {
        $model = new static;
        $key = collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))->firstWhere('name', 'id');

        return $key && ! ($key['auto_increment'] ?? false) && in_array(strtolower($key['type_name']), ['char', 'varchar', 'text'], true);
    }

    public static function flushKeyTypeCache(): void
    {
        // Schema metadata is read from this model's connection on each insert.
    }
}
