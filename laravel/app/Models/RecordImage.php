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
     * Detect whether the primary key column is a non-auto-increment string type
     * that needs a manually generated ID.  The result is cached per connection
     * name so the schema query runs at most once per process.
     */
    private static function requiresStringKey(self $image): bool
    {
        $connectionName = $image->getConnectionName() ?? 'default';

        if (! app()->runningUnitTests() && array_key_exists($connectionName, self::$needsStringKey)) {
            return self::$needsStringKey[$connectionName];
        }

        try {
            $columns = $image->getConnection()->getSchemaBuilder()->getColumns($image->getTable());
            $key = collect($columns)->firstWhere('name', $image->getKeyName());

            if (! $key) {
                // Column metadata unavailable — assume string key to be safe.
                return self::$needsStringKey[$connectionName] = true;
            }

            if ($key['auto_increment'] ?? false) {
                return self::$needsStringKey[$connectionName] = false;
            }

            // Match the base type returned by the database driver.  MySQL returns
            // names like 'varchar', 'char', 'text'; SQLite returns 'varchar',
            // 'text', or 'integer'; MariaDB may return 'varchar' as well.
            $stringTypes = ['char', 'varchar', 'text', 'nvarchar', 'nchar', 'tinytext', 'mediumtext', 'longtext'];

            return self::$needsStringKey[$connectionName] = in_array(strtolower((string) ($key['type_name'] ?? '')), $stringTypes, true);
        } catch (\Throwable) {
            // Schema introspection failed (restricted information_schema, connection
            // issues, etc.).  Default to generating a string key — inserting a
            // string into an auto-increment int column is less harmful than
            // inserting NULL into a non-auto-increment string column.
            return self::$needsStringKey[$connectionName] = true;
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
