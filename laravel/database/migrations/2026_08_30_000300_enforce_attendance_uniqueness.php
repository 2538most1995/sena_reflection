<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('record_attendance')) {
            return;
        }
        $duplicates = DB::table('record_attendance')->select(['record_id', 'student_id'])->groupBy(['record_id', 'student_id'])->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Duplicate attendance rows must be resolved before adding the unique constraint.');
        }
        $exists = DB::getDriverName() === 'sqlite'
            ? collect(DB::select("PRAGMA index_list('record_attendance')"))->contains(fn ($row) => $row->name === 'uq_attendance_record_student')
            : DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'record_attendance')->where('index_name', 'uq_attendance_record_student')->exists();
        if (! $exists) {
            Schema::table('record_attendance', fn (Blueprint $table) => $table->unique(['record_id', 'student_id'], 'uq_attendance_record_student'));
        }
    }

    public function down(): void {}
};
