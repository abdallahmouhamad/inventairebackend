<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiches_comptage', function (Blueprint $table) {
            $table->boolean('est_recomptage')->default(false);
            $table->foreignUuid('fiche_initiale_id')->nullable()->constrained('fiches_comptage');
        });
    }

    public function down(): void
    {
        Schema::table('fiches_comptage', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiche_initiale_id');
            $table->dropColumn('est_recomptage');
        });
    }
};
