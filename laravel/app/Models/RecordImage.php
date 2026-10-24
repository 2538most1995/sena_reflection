<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecordImage extends Model
{
    public $timestamps = false;

    protected $table = 'record_images';

    protected $keyType = 'string';

    protected $fillable = ['id', 'record_id', 'file_path', 'created_at'];

    protected static function booted(): void
    {
        static::creating(static function (self $image): void {
            $columns = $image->getConnection()->getSchemaBuilder()->getColumns($image->getTable());
            $key = collect($columns)->firstWhere('name', $image->getKeyName());
            if (! $key || ($key['auto_increment'] ?? false) || ! in_array(strtolower($key['type_name']), ['char', 'varchar', 'text'], true)) {
                return;
            }
            $image->setIncrementing(false);
            if ($image->getKey() === null) {
                $image->setAttribute($image->getKeyName(), 'i'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19));
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public static function createWithAdaptiveKey(array $attributes): self
    {
        return static::query()->create($attributes);
    }

    public static function flushKeyTypeCache(): void
    {
        // Schema metadata is read from this model's connection on each insert.
    }
}
