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
        Schema::create('perimetre_rayon', function (Blueprint $table) {
            $table->foreignUuid('perimetre_id')->constrained('perimetres')->cascadeOnDelete();
            $table->string('code_rayon');
            $table->primary(['perimetre_id', 'code_rayon']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perimetre_rayon');
    }
};
