<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Centralise la construction des requetes filtrees consommees par les
 * GraphQL Queries (une methode statique getQueryXxx($args, $root) par entite),
 * pour rester coherent avec la convention utilisee dans les autres projets
 * backend de l'entreprise.
 */
class QueryModel
{
    /**
     * @param array<string, mixed> $args
     * @param mixed $root
     * @return Builder<Utilisateur>
     */
    public static function getQueryUtilisateur(array $args, mixed $root = null): Builder
    {
        $query = Utilisateur::query()->with('role');

        if (isset($args['id'])) {
            return $query->where('id', $args['id']);
        }

        if (!empty($args['recherche'])) {
            $terme = $args['recherche'];
            $query->where(function (Builder $q) use ($terme) {
                $q->where('prenom', 'ilike', "%{$terme}%")
                    ->orWhere('nom', 'ilike', "%{$terme}%")
                    ->orWhere('email', 'ilike', "%{$terme}%");
            });
        }

        if (isset($args['role_code'])) {
            $query->whereHas('role', function (Builder $q) use ($args) {
                $q->where('code', $args['role_code']);
            });
        }

        if (isset($args['est_actif'])) {
            $query->where('est_actif', $args['est_actif']);
        }

        return $query;
    }
}
