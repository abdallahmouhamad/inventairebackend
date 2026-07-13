<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Codes fixes documentes dans FRONTEND_CONTEXT.md (UserRole) et le document
     * fonctionnel (Partie II.3 - Acteurs et roles). Cette liste est fermee : toute
     * evolution necessite aussi une mise a jour du RBAC front (lib/permissions.ts)
     * et du back, pas seulement un ajout de ligne en base.
     */
    private const ROLES = [
        ['code' => 'OPERATOR', 'libelle' => 'Operateur terrain'],
        ['code' => 'MOBILE_MANAGER', 'libelle' => 'Responsable terrain mobile'],
        ['code' => 'SUPER_ADMIN', 'libelle' => 'Super administrateur'],
        ['code' => 'INVENTORY_MANAGER', 'libelle' => 'Responsable inventaire'],
        ['code' => 'READONLY', 'libelle' => 'Lecture seule'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('libelle');
            $table->timestamps();
        });

        $now = now();

        DB::table('roles')->insert(array_map(
            static fn (array $role) => [
                'id' => (string) Str::uuid(),
                'code' => $role['code'],
                'libelle' => $role['libelle'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::ROLES,
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
