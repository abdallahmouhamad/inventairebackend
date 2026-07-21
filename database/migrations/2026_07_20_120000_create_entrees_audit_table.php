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
        Schema::create('entrees_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('acteur_id')->constrained('utilisateurs');
            $table->string('action');
            $table->string('cible_type')->nullable();
            $table->uuid('cible_id')->nullable();
            $table->jsonb('metadonnees')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action']);
            $table->index(['cible_type', 'cible_id']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entrees_audit');
    }
};
