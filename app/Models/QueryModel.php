<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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

    /**
     * @param array<string, mixed> $args
     * @param mixed $root
     * @return Builder<SessionInventaire>
     */
    public static function getQuerySessionInventaire(array $args, mixed $root = null): Builder
    {
        $query = SessionInventaire::query()->with('ouvertePar');

        self::scoperSites($query, 'code_site');

        if (isset($args['id'])) {
            return $query->where('id', $args['id']);
        }

        if (!empty($args['recherche'])) {
            $terme = $args['recherche'];
            $query->where(function (Builder $q) use ($terme) {
                $q->where('code', 'ilike', "%{$terme}%")
                    ->orWhere('nom', 'ilike', "%{$terme}%");
            });
        }

        if (isset($args['code_site'])) {
            $query->where('code_site', $args['code_site']);
        }

        if (isset($args['statut'])) {
            $query->where('statut', $args['statut']);
        }

        return $query->orderByDesc('date_debut');
    }

    /**
     * Restreint la requete aux sites de l'utilisateur connecte (vide =
     * SUPER_ADMIN/READONLY = tous sites). Meme regle que
     * SessionInventairePolicy, appliquee ici pour couvrir a la fois les
     * listes REST et GraphQL (une Policy ne filtre pas une collection).
     *
     * @param Builder<SessionInventaire> $query
     */
    private static function scoperSites(Builder $query, string $colonneCodeSite): void
    {
        /** @var Utilisateur|null $acteur */
        $acteur = Auth::guard('api')->user();

        if (!$acteur) {
            return;
        }

        $codesSites = $acteur->codesSites();

        if (!empty($codesSites)) {
            $query->whereIn($colonneCodeSite, $codesSites);
        }
    }
}
