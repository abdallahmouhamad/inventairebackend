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
        Schema::create('tentatives_acces_perimetre', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('sessions_inventaire')->cascadeOnDelete();
            $table->string('code_depot');
            $table->string('code_rayon');
            $table->foreignUuid('agent_id')->constrained('utilisateurs');
            $table->foreignUuid('perimetre_conflit_id')->nullable()->constrained('perimetres')->nullOnDelete();
            $table->timestamp('tentee_le');
            $table->timestamp('resolue_le')->nullable();
            $table->foreignUuid('resolue_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tentatives_acces_perimetre');
    }
};
