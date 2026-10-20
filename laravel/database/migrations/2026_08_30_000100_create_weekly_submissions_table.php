<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('weekly_submissions')) {
            Schema::create('weekly_submissions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tambon', 20)->index();
                $table->unsignedTinyInteger('week');
                $table->string('semester', 10);
                $table->string('year', 10);
                $table->string('submitted_by', 20)->index();
                $table->string('status', 20)->default('complete');
                $table->timestamps();
                $table->unique(['tambon', 'year', 'semester', 'week'], 'uq_weekly_submission_scope');
                $table->index(['year', 'semester', 'week'], 'idx_weekly_period');
            });
        }

        if (! Schema::hasColumn('records', 'weekly_submission_id')) {
            Schema::table('records', function (Blueprint $table) {
                $table->uuid('weekly_submission_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive during the legacy-to-Laravel transition.
    }
};
