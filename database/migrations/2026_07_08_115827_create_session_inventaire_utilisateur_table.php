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
        Schema::create('session_inventaire_utilisateur', function (Blueprint $table) {
            $table->foreignUuid('session_inventaire_id')->constrained('sessions_inventaire')->cascadeOnDelete();
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->primary(['session_inventaire_id', 'utilisateur_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_inventaire_utilisateur');
    }
};
