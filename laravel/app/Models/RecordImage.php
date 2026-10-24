<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecordImage extends Model
{
    public $timestamps = false;

    protected $table = 'record_images';

    // Imported installations can retain string image IDs instead of auto-increment keys.
    protected $keyType = 'string';

    protected $fillable = ['id', 'record_id', 'file_path', 'created_at'];

    /** @var array<string, bool> Cached per-connection result: true = string key needing manual ID */
    private static array $needsStringKey = [];

    protected static function booted(): void
    {
        static::creating(static function (self $image): void {
            if (! self::requiresStringKey($image)) {
                return;
            }
            $image->setIncrementing(false);
            if ($image->getKey() === null) {
                $image->setAttribute($image->getKeyName(), 'i'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19));
            }
        });
    }

    /**
     * Create a record image with adaptive ID handling: if the column requires
     * a string ID or is auto-increment integer, handle both seamlessly.
     */
    public static function createWithAdaptiveKey(array $attributes): self
    {
        $instance = new static($attributes);

        try {
            $instance->save();

            return $instance;
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = $e->getMessage();
            $connectionName = $instance->getConnectionName() ?? 'default';

            // If MySQL complained about missing default value for id (error 1364 or 1048), force string key
            if (str_contains($msg, "Field 'id' doesn't have a default value")
                || str_contains($msg, "Column 'id' cannot be null")
                || str_contains($msg, 'NOT NULL constraint failed: record_images.id')) {
                self::$needsStringKey[$connectionName] = true;
                $instance->setIncrementing(false);
                $instance->setAttribute($instance->getKeyName(), 'i'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19));
                $instance->save();

                return $instance;
            }

            // If MySQL complained about incorrect integer value for id (error 1366), force auto-increment int
            if (str_contains($msg, 'Incorrect integer value') && str_contains($msg, "'id'")) {
                self::$needsStringKey[$connectionName] = false;
                $instance->setIncrementing(true);
                $instance->setAttribute($instance->getKeyName(), null);
                $instance->save();

                return $instance;
            }

            throw $e;
        }
    }

    /**
     * Detect whether the primary key column is a non-auto-increment string type
     * that needs a manually generated ID. The result is cached per connection
     * name so the schema query runs at most once per process.
     */
    private static function requiresStringKey(self $image): bool
    {
        $connectionName = $image->getConnectionName() ?? 'default';

        if (! app()->runningUnitTests() && array_key_exists($connectionName, self::$needsStringKey)) {
            return self::$needsStringKey[$connectionName];
        }

        try {
            $connection = $image->getConnection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                $column = $connection->selectOne("SHOW COLUMNS FROM `{$image->getTable()}` LIKE ?", [$image->getKeyName()]);
                if ($column) {
                    $column = (object) array_change_key_case((array) $column, CASE_LOWER);
                    $isAutoIncrement = str_contains(strtolower((string) ($column->extra ?? '')), 'auto_increment');
                    if ($isAutoIncrement) {
                        return self::$needsStringKey[$connectionName] = false;
                    }
                    $type = strtolower((string) ($column->type ?? ''));
                    $isNumeric = preg_match('/^(int|bigint|smallint|tinyint|mediumint)/i', $type) === 1;
                    if ($isNumeric) {
                        return self::$needsStringKey[$connectionName] = false;
                    }

                    return self::$needsStringKey[$connectionName] = true;
                }
            }

            $columns = $connection->getSchemaBuilder()->getColumns($image->getTable());
            $key = collect($columns)->firstWhere('name', $image->getKeyName());

            if (! $key) {
                return self::$needsStringKey[$connectionName] = false;
            }

            if ($key['auto_increment'] ?? false) {
                return self::$needsStringKey[$connectionName] = false;
            }

            $type = strtolower((string) ($key['type_name'] ?? ''));
            $isNumeric = in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true);

            return self::$needsStringKey[$connectionName] = ! $isNumeric;
        } catch (\Throwable) {
            return self::$needsStringKey[$connectionName] = false;
        }
    }

    /**
     * Flush the cached key-type detection.  Call in tests when altering the
     * record_images schema within a single process.
     */
    public static function flushKeyTypeCache(): void
    {
        self::$needsStringKey = [];
    }
}
