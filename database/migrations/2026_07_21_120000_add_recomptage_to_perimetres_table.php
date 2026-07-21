<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perimetres', function (Blueprint $table) {
            $table->foreignUuid('recount_agent_id')->nullable()->constrained('utilisateurs');
            $table->text('motif_recomptage')->nullable();
            $table->timestamp('recount_requested_at')->nullable();
            $table->foreignUuid('recount_requested_by_id')->nullable()->constrained('utilisateurs');
            $table->timestamp('recount_submitted_at')->nullable();
            $table->timestamp('arbitrated_at')->nullable();
            $table->foreignUuid('arbitrated_by_id')->nullable()->constrained('utilisateurs');
        });
    }

    public function down(): void
    {
        Schema::table('perimetres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recount_agent_id');
            $table->dropColumn('motif_recomptage');
            $table->dropColumn('recount_requested_at');
            $table->dropConstrainedForeignId('recount_requested_by_id');
            $table->dropColumn('recount_submitted_at');
            $table->dropColumn('arbitrated_at');
            $table->dropConstrainedForeignId('arbitrated_by_id');
        });
    }
};
