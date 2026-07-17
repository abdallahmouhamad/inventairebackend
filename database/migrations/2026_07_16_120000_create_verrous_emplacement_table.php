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
        Schema::create('verrous_emplacement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('sessions_inventaire')->cascadeOnDelete();
            $table->foreignUuid('perimetre_id')->constrained('perimetres')->cascadeOnDelete();
            $table->string('code_depot');
            $table->string('code_rayon');
            $table->string('code_emplacement');
            $table->foreignUuid('agent_id')->constrained('utilisateurs');
            $table->timestamp('verrouille_le');
            $table->timestamp('derniere_activite_le');
            $table->timestamp('libere_le')->nullable();
            $table->foreignUuid('libere_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->boolean('force_libere')->default(false);
            $table->text('motif_liberation_forcee')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verrous_emplacement');
    }
};
