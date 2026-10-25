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
        Schema::create('semester_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('year', 10);
            $table->string('semester', 10);
            $table->string('status', 20)->default('ready');
            $table->string('created_by', 20);
            $table->string('storage_path', 255);
            $table->string('sha256', 64);
            $table->string('fingerprint', 64);
            $table->json('counts');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['year', 'semester']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retain the backup catalog on rollback; its files can be the only saved copy.
    }
};
