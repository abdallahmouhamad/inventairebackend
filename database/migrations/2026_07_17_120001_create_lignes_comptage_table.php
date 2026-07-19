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
        Schema::create('lignes_comptage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiche_comptage_id')->constrained('fiches_comptage')->cascadeOnDelete();
            $table->string('code_article');
            $table->string('nom_article')->nullable();
            $table->string('code_emplacement');
            $table->string('numero_lot');
            $table->string('numero_lot_parent')->nullable();
            $table->date('date_peremption')->nullable();
            $table->boolean('est_correction_lot')->default(false);
            $table->boolean('est_hors_liste')->default(false);
            $table->unsignedInteger('qte_theorique_itu')->nullable();
            $table->unsignedInteger('qte_theorique_stu')->nullable();
            $table->unsignedInteger('qte_comptee_itu');
            $table->unsignedInteger('qte_comptee_stu');
            $table->string('statut_review');
            $table->text('commentaire_rejet')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_comptage');
    }
};
