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
        Schema::create('sessions_inventaire', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('nom');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('statut');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->string('x3_session_id');
            $table->timestamp('importee_de_x3_le');
            $table->timestamp('ouverte_aux_agents_le')->nullable();
            $table->foreignUuid('ouverte_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->unsignedInteger('total_lignes')->nullable();
            $table->unsignedInteger('lignes_soumises')->nullable();
            $table->unsignedInteger('lignes_validees')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions_inventaire');
    }
};
