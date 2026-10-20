<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value')->nullable();
            });
        }

        if (! Schema::hasTable('tambons')) {
            Schema::create('tambons', function (Blueprint $table) {
                $table->string('id', 20)->primary();
                $table->string('name', 100);
                $table->string('icon', 20)->default('📍');
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->string('id', 20)->primary();
                $table->string('name', 100);
                $table->string('username', 50)->unique();
                $table->string('password');
                $table->string('role', 20)->default('tambon')->index();
                $table->string('tambon', 20)->nullable()->index();
                $table->string('avatar_path')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('records')) {
            Schema::create('records', function (Blueprint $table) {
                $table->string('id', 20)->primary();
                $table->string('tambon', 20)->index();
                $table->unsignedTinyInteger('week');
                $table->date('date');
                $table->string('level', 50)->nullable();
                $table->string('semester', 10)->default('1');
                $table->string('year', 10);
                $table->string('subject', 150);
                $table->string('subject_code', 50)->nullable();
                $table->string('class_group_code', 50)->nullable();
                $table->string('class_name')->nullable();
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('present')->default(0);
                $table->text('result')->nullable();
                $table->text('problem')->nullable();
                $table->text('suggestion')->nullable();
                $table->text('supervisor_comment')->nullable();
                $table->string('created_by', 20)->nullable()->index();
                $table->timestamps();
                $table->index(['tambon', 'year', 'semester', 'week', 'level'], 'idx_records_scope_week');
            });
        }

        if (! Schema::hasTable('record_images')) {
            Schema::create('record_images', function (Blueprint $table) {
                $table->id();
                $table->string('record_id', 20)->index();
                $table->string('file_path');
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('record_attendance')) {
            Schema::create('record_attendance', function (Blueprint $table) {
                $table->id();
                $table->string('record_id', 20)->index();
                $table->string('student_id', 50);
                $table->string('full_name');
                $table->string('group_code', 50)->nullable();
                $table->string('class_name')->nullable();
                $table->boolean('present')->default(false);
                $table->string('learning_method', 30)->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->unique(['record_id', 'student_id'], 'uq_attendance_record_student');
            });
        }
    }

    public function down(): void
    {
        // Compatibility migration: never remove tables that can contain legacy data.
    }
};
