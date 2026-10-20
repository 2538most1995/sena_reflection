<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('record_document_layouts')) {
            Schema::create('record_document_layouts', function (Blueprint $table) {
                $table->string('record_id', 20)->primary();
                $table->json('settings');
                $table->json('content');
                $table->string('updated_by', 20)->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive because layouts can be attached to legacy records.
    }
};
