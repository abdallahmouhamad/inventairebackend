<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\SessionInventaire;
use App\Models\Utilisateur;

/**
 * session.view / session.open (FRONTEND_CONTEXT.md §4). Scoping par site :
 * un utilisateur avec codesSites() vide voit tous les sites (SUPER_ADMIN,
 * READONLY dans nos donnees de demo) ; sinon restreint aux sites attaches
 * (INVENTORY_MANAGER) -- regle §8.3 du document fonctionnel, centralisee ici
 * plutot que dispersee dans le controleur.
 */
class SessionInventairePolicy
{
    public function viewAny(Utilisateur $acteur): bool
    {
        return true;
    }

    public function view(Utilisateur $acteur, SessionInventaire $session): bool
    {
        return $this->aAccesAuSite($acteur, $session->code_site);
    }

    public function open(Utilisateur $acteur, SessionInventaire $session): bool
    {
        if (!in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true)) {
            return false;
        }

        return $this->aAccesAuSite($acteur, $session->code_site);
    }

    /**
     * Declencher un PULL depuis X3 -- meme garde que sync.trigger_inbound
     * (FRONTEND_CONTEXT.md §4 : SUPER_ADMIN/INVENTORY_MANAGER seulement).
     */
    public function synchroniser(Utilisateur $acteur): bool
    {
        return in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true);
    }

    /**
     * Autoriser/retirer un agent mobile sur la session -- meme garde que
     * open (le responsable du site gere ses agents).
     */
    public function gererAgents(Utilisateur $acteur, SessionInventaire $session): bool
    {
        if (!in_array($acteur->role->code, [Role::SUPER_ADMIN, Role::INVENTORY_MANAGER], true)) {
            return false;
        }

        return $this->aAccesAuSite($acteur, $session->code_site);
    }

    private function aAccesAuSite(Utilisateur $acteur, string $codeSite): bool
    {
        $codesSites = $acteur->codesSites();

        return empty($codesSites) || in_array($codeSite, $codesSites, true);
    }
}
