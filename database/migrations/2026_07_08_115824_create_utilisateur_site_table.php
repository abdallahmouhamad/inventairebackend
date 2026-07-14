<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoping site des utilisateurs (INVENTORY_MANAGER notamment). Le site n'est
 * pas une entite persistee localement (referentiel = Sage X3 en direct via
 * RererentielX3, jamais duplique en base) : on stocke donc le code_site tel
 * quel, sans cle etrangere vers une table sites locale.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('utilisateur_site', function (Blueprint $table) {
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('code_site');
            $table->primary(['utilisateur_id', 'code_site']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('utilisateur_site');
    }
};
