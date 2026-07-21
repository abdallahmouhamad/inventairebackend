<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Utilisateur;

/**
 * audit.view (tous roles web) / audit.export (doc fonctionnel §8.2 : SUPER_ADMIN
 * et READONLY seulement -- INVENTORY_MANAGER peut consulter mais pas exporter,
 * tel quel dans la matrice de permissions, pas une erreur de recopie).
 */
class EntreeAuditPolicy
{
    public function viewAny(Utilisateur $acteur): bool
    {
        return true;
    }

    public function export(Utilisateur $acteur): bool
    {
        return in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::READONLY], true);
    }
}
