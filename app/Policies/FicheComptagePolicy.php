<?php

namespace App\Policies;

use App\Models\FicheComptage;
use App\Models\Role;
use App\Models\Utilisateur;

/**
 * submission.view / submission.review / submission.validate /
 * submission.send_revision (doc fonctionnel §8.2). Meme principe de
 * scoping par site que PerimetrePolicy, via la session parente.
 */
class FicheComptagePolicy
{
    public function viewAny(Utilisateur $acteur): bool
    {
        return true;
    }

    public function view(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        return $this->aAccesAuSite($acteur, $fiche);
    }

    public function review(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        return $this->reserveResponsable($acteur, $fiche);
    }

    public function validate(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        return $this->reserveResponsable($acteur, $fiche);
    }

    public function sendRevision(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        return $this->reserveResponsable($acteur, $fiche);
    }

    private function reserveResponsable(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        if (!in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true)) {
            return false;
        }

        return $this->aAccesAuSite($acteur, $fiche);
    }

    private function aAccesAuSite(Utilisateur $acteur, FicheComptage $fiche): bool
    {
        $codesSites = $acteur->codesSites();

        return empty($codesSites) || in_array($fiche->session->code_site, $codesSites, true);
    }
}
