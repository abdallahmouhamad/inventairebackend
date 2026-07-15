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
        Schema::create('perimetres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('sessions_inventaire')->cascadeOnDelete();
            $table->string('code_depot');
            $table->string('statut');
            $table->foreignUuid('agent_declarant_id')->constrained('utilisateurs');
            $table->timestamp('declare_le');
            $table->timestamp('libere_le')->nullable();
            $table->foreignUuid('libere_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->text('motif_liberation_forcee')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perimetres');
    }
};
