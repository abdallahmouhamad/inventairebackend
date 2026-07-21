<?php

namespace App\Policies;

use App\Models\Perimetre;
use App\Models\Role;
use App\Models\Utilisateur;

/**
 * perimeter.view / perimeter.force_release (doc fonctionnel §8.2). Meme
 * principe de scoping par site que SessionInventairePolicy, applique via le
 * site de la session parente (le perimetre n'a pas de code_site propre).
 */
class PerimetrePolicy
{
    public function viewAny(Utilisateur $acteur): bool
    {
        return true;
    }

    public function view(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->aAccesAuSite($acteur, $perimetre);
    }

    public function forceRelease(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        if (!in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true)) {
            return false;
        }

        return $this->aAccesAuSite($acteur, $perimetre);
    }

    /**
     * Le tableau des routes (§6.3) marque "resolve" comme perimeter.view,
     * mais §2.3 decrit READONLY comme "consultation ... aucune action de
     * modification" -- incoherence interne au document. Choix retenu ici :
     * traiter resolve comme les autres actions de mutation du module
     * (SUPER_ADMIN/INVENTORY_MANAGER), pour rester coherent avec la
     * definition du role plutot qu'avec le libelle isole de cette ligne.
     */
    public function resoudreTentative(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    /**
     * perimeter.request_recount / perimeter.arbitrate / perimeter.validate
     * (FRONTEND_CONTEXT.md §4) : memes habilitations que forceRelease
     * (SUPER_ADMIN/INVENTORY_MANAGER, memes site), READONLY toujours en
     * lecture seule sur tout le cycle recomptage/arbitrage.
     */
    public function requestRecount(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    public function cancelRecount(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    public function assignRecountAgent(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    public function arbitrate(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    public function relaunch(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        return $this->forceRelease($acteur, $perimetre);
    }

    private function aAccesAuSite(Utilisateur $acteur, Perimetre $perimetre): bool
    {
        $codesSites = $acteur->codesSites();

        return empty($codesSites) || in_array($perimetre->session->code_site, $codesSites, true);
    }
}
