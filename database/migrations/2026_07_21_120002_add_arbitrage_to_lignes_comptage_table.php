<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_comptage', function (Blueprint $table) {
            $table->foreignUuid('ligne_appariee_id')->nullable()->constrained('lignes_comptage');
            $table->string('resultat_arbitrage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lignes_comptage', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ligne_appariee_id');
            $table->dropColumn('resultat_arbitrage');
        });
    }
};
