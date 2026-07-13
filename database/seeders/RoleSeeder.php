<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Codes fixes documentes dans FRONTEND_CONTEXT.md (UserRole) et le document
 * fonctionnel (Partie II.3 - Acteurs et roles). Cette liste est fermee : toute
 * evolution necessite aussi une mise a jour du RBAC front (lib/permissions.ts)
 * et du back, pas seulement un ajout de ligne en base.
 */
class RoleSeeder extends Seeder
{
    private const ROLES = [
        ['code' => Role::OPERATOR, 'libelle' => 'Operateur terrain'],
        ['code' => Role::MOBILE_MANAGER, 'libelle' => 'Responsable terrain mobile'],
        ['code' => Role::SUPER_ADMIN, 'libelle' => 'Super administrateur'],
        ['code' => Role::INVENTORY_MANAGER, 'libelle' => 'Responsable inventaire'],
        ['code' => Role::READONLY, 'libelle' => 'Lecture seule'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['code' => $role['code']], ['libelle' => $role['libelle']]);
        }
    }
}
