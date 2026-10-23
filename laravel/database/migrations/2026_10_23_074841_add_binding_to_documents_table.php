<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('binding_key', 100)->nullable()->unique();
            $table->json('binding_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique(['binding_key']);
            $table->dropColumn(['binding_key', 'binding_snapshot']);
        });
    }
};
