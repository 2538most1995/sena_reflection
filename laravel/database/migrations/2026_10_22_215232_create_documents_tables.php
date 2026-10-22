<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id', 20)->index();
            $table->string('source_record_id', 20)->nullable();
            $table->string('title', 200);
            $table->json('content_json');
            $table->json('page_settings_json');
            $table->json('objects_json');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'source_record_id'], 'documents_owner_source_unique');
        });
        Schema::create('document_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('storage_path', 255);
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
        });
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('user_id', 20);
            $table->unsignedInteger('revision');
            $table->json('snapshot_json');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('document_assets');
        Schema::dropIfExists('documents');
    }
};
