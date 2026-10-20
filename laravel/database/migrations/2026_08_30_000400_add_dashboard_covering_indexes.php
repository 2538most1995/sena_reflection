<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->add('idx_records_tambon_period_date', ['tambon', 'year', 'semester', 'week', 'date', 'created_at']);
        $this->add('idx_records_period_date', ['year', 'semester', 'week', 'date', 'created_at']);
    }

    private function add(string $name, array $columns): void
    {
        $exists = DB::getDriverName() === 'sqlite' ? collect(DB::select("PRAGMA index_list('records')"))->contains(fn ($row) => $row->name === $name) : DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'records')->where('index_name', $name)->exists();
        if (! $exists) {
            Schema::table('records', fn (Blueprint $table) => $table->index($columns, $name));
        }
    }

    public function down(): void {}
};
