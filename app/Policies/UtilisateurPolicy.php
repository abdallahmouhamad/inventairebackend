<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Utilisateur;

/**
 * Aucune permission "users.*" n'existe dans la matrice RBAC documentee
 * (FRONTEND_CONTEXT.md §4) -- la gestion des comptes n'y est pas prevue
 * explicitement. Reserve a SUPER_ADMIN uniquement, par analogie avec
 * settings.update qui suit la meme logique (parametrage systeme sensible).
 */
class UtilisateurPolicy
{
    public function create(Utilisateur $acteur): bool
    {
        return $acteur->role->code === Role::SUPER_ADMIN;
    }

    public function update(Utilisateur $acteur, Utilisateur $cible): bool
    {
        return $acteur->role->code === Role::SUPER_ADMIN;
    }

    public function delete(Utilisateur $acteur, Utilisateur $cible): bool
    {
        return $acteur->role->code === Role::SUPER_ADMIN && $acteur->id !== $cible->id;
    }
}
