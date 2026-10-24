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
        $repairs = [];
        foreach (['record_images', 'record_attendance'] as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $column = collect($schema->getColumns($table))->firstWhere('name', 'id');
            if (! $column || ($column['auto_increment'] ?? false)
                || ! in_array(strtolower($column['type_name']), ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
                continue;
            }
            $nulls = $connection->table($table)->whereNull('id')->count();
            if ($nulls) {
                throw new RuntimeException("Cannot restore {$table}.id generation: null_rows={$nulls}. Existing IDs and rows were not changed; resolve null identities before retrying.");
            }
            $type = $column['type'];
            if (! preg_match('/\A(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?\z/i', $type)) {
                throw new RuntimeException("Unsupported numeric key type for {$table}.id.");
            }

            // InnoDB only needs a leading ID index, not uniqueness. Imported zero IDs
            // can repeat across photos and must retain their document/history anchors.
            $indexes = $schema->getIndexes($table);
            $indexedId = collect($indexes)->contains(static fn (array $index): bool => ($index['columns'][0] ?? null) === 'id');
            $indexName = $table.'_generated_id_index';
            $suffix = 0;
            while (in_array($indexName, array_column($indexes, 'name'), true)) {
                $indexName = $table.'_generated_id_index_'.(++$suffix);
            }
            $repairs[] = compact('table', 'type', 'indexedId', 'indexName');
        }

        if ($repairs === []) {
            return;
        }
        $mode = (string) $connection->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $grammar = $connection->getQueryGrammar();
        try {
            // Keep imported zero IDs and their document anchors intact during ALTER.
            $connection->statement('SET SESSION sql_mode = ?', [implode(',', array_unique([...array_filter(explode(',', $mode)), 'NO_AUTO_VALUE_ON_ZERO']))]);
            foreach ($repairs as $repair) {
                $statement = 'ALTER TABLE '.$grammar->wrapTable($repair['table']).' MODIFY '.$grammar->wrap('id').' '.$repair['type'].' NOT NULL AUTO_INCREMENT';
                if (! $repair['indexedId']) {
                    $statement .= ', ADD INDEX '.$grammar->wrap($repair['indexName']).' ('.$grammar->wrap('id').')';
                }
                $connection->statement($statement);
            }
        } finally {
            $connection->statement('SET SESSION sql_mode = ?', [$mode]);
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
