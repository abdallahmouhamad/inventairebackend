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
        Schema::create('fiches_comptage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('sessions_inventaire')->cascadeOnDelete();
            $table->foreignUuid('perimetre_id')->constrained('perimetres')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained('utilisateurs');
            $table->string('statut');
            $table->timestamp('soumise_le');
            $table->text('commentaire_revision')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiches_comptage');
    }
};
