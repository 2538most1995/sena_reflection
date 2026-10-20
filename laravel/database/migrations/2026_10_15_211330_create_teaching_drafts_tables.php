<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teaching_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id', 20)->index();
            $table->string('tambon', 20)->nullable();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('semester', 10)->nullable();
            $table->string('year', 10)->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamp('last_saved_at')->nullable();
            $table->string('source_record_id', 20)->nullable()->index();
            $table->unsignedInteger('source_revision')->nullable();
            $table->uuid('finalized_submission_id')->nullable()->index();
            $table->uuid('finalize_key')->nullable()->unique();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'updated_at'], 'idx_drafts_owner_status');
            $table->index(['user_id', 'tambon', 'year', 'semester', 'week'], 'idx_drafts_owner_scope');
        });

        Schema::create('draft_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('teaching_draft_id');
            $table->string('level_code', 1);
            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('teaching_draft_id')->references('id')->on('teaching_drafts')->cascadeOnDelete();
            $table->index(['teaching_draft_id', 'level_code', 'sort_order'], 'idx_draft_images_level_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drafts can contain unsent user work; rollback must not remove them.
    }
};
