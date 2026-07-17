<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Utilisateur;
use App\Models\VerrouEmplacement;

/**
 * lock.view / lock.force_release (doc fonctionnel §8.2). Meme principe de
 * scoping par site que PerimetrePolicy, via la session parente.
 */
class VerrouEmplacementPolicy
{
    public function viewAny(Utilisateur $acteur): bool
    {
        return true;
    }

    public function view(Utilisateur $acteur, VerrouEmplacement $verrou): bool
    {
        return $this->aAccesAuSite($acteur, $verrou);
    }

    public function forceRelease(Utilisateur $acteur, VerrouEmplacement $verrou): bool
    {
        if (!in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true)) {
            return false;
        }

        return $this->aAccesAuSite($acteur, $verrou);
    }

    private function aAccesAuSite(Utilisateur $acteur, VerrouEmplacement $verrou): bool
    {
        $codesSites = $acteur->codesSites();

        return empty($codesSites) || in_array($verrou->session->code_site, $codesSites, true);
    }
}
