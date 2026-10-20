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
        Schema::create('record_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('record_id', 20)->index();
            $table->unsignedInteger('revision');
            $table->string('content_hash', 64)->index();
            $table->json('snapshot');
            $table->string('created_by', 20);
            $table->timestamp('created_at');
            $table->unique(['record_id', 'revision']);
        });
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id', 20)->index();
            $table->string('status', 20)->default('queued')->index();
            $table->json('filters');
            $table->json('manifest');
            $table->unsignedInteger('total');
            $table->unsignedInteger('completed')->default(0);
            $table->string('path')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep document history on rollback during the migration period.
    }
};
