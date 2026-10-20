<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('records', 'idx_records_scope_week', ['tambon', 'year', 'semester', 'week', 'level']);
        $this->addIndexIfMissing('records', 'idx_records_creator_period', ['created_by', 'year', 'semester', 'week']);
        $this->addIndexIfMissing('record_images', 'idx_record_images_record_id', ['record_id']);
        $this->addIndexIfMissing('record_attendance', 'idx_record_attendance_record_id', ['record_id']);
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('".$table."')"))->contains(fn ($row) => $row->name === $name);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }

    public function down(): void
    {
        // Indexes are safe to retain and may be shared by the legacy app.
    }
};
