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
        Schema::create('emplacements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rayon_id')->constrained('rayons')->cascadeOnDelete();
            $table->string('code');
            $table->string('libelle');
            $table->timestamps();

            $table->unique(['rayon_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emplacements');
    }
};
