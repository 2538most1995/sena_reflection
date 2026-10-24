<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $schema = $connection->getSchemaBuilder();
        foreach (['record_images', 'record_attendance'] as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $column = collect($schema->getColumns($table))->firstWhere('name', 'id');
            if (! $column || ($column['auto_increment'] ?? false)
                || ! in_array(strtolower($column['type_name']), ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
                continue;
            }
            $primary = collect($schema->getIndexes($table))->firstWhere('primary', true);
            if (($primary['columns'] ?? []) !== ['id'] || $connection->table($table)->whereNull('id')->exists()) {
                throw new RuntimeException("Cannot restore {$table}.id generation: a non-null, single-column primary key is required.");
            }
            $type = $column['type'];
            if (! preg_match('/\A(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?\z/i', $type)) {
                throw new RuntimeException("Unsupported numeric key type for {$table}.id.");
            }

            $mode = (string) $connection->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            $grammar = $connection->getQueryGrammar();
            try {
                // Keep imported zero IDs and their document anchors intact during ALTER.
                $connection->statement('SET SESSION sql_mode = ?', [implode(',', array_unique([...array_filter(explode(',', $mode)), 'NO_AUTO_VALUE_ON_ZERO']))]);
                $connection->statement('ALTER TABLE '.$grammar->wrapTable($table).' MODIFY '.$grammar->wrap('id').' '.$type.' NOT NULL AUTO_INCREMENT');
            } finally {
                $connection->statement('SET SESSION sql_mode = ?', [$mode]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This repair preserves existing IDs; rolling back must not disable ID generation again.
    }
};
