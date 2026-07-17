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
     * Sessions visibles par un agent mobile : uniquement celles ou il figure
     * dans les agents autorises, jamais IMPORTED_FROM_X3 (une session pas
     * encore ouverte par le responsable ne doit jamais apparaitre cote
     * mobile -- FRONTEND_CONTEXT.md §3.3).
     *
     * @return Builder<SessionInventaire>
     */
    public static function getQuerySessionInventaireMobile(Utilisateur $agent): Builder
    {
        return SessionInventaire::query()
            ->whereHas('utilisateursAutorises', function (Builder $q) use ($agent) {
                $q->where('utilisateurs.id', $agent->id);
            })
            ->where('statut', '!=', SessionInventaire::STATUT_IMPORTED_FROM_X3)
            ->orderByDesc('date_debut');
    }

    /**
     * @param array<string, mixed> $args
     * @param mixed $root
     * @return Builder<Perimetre>
     */
    public static function getQueryPerimetre(array $args, mixed $root = null): Builder
    {
        $query = Perimetre::query()->with('agentDeclarant');

        self::scoperSitesViaSession($query);

        if (isset($args['id'])) {
            return $query->where('id', $args['id']);
        }

        if (isset($args['session_id'])) {
            $query->where('session_id', $args['session_id']);
        }

        if (isset($args['statut'])) {
            $query->where('statut', $args['statut']);
        }

        return $query->orderByDesc('declare_le');
    }

    /**
     * Perimetres declares par l'agent mobile connecte -- lui permet de
     * retrouver son/ses perimetre(s) actif(s) sans dependre d'un cache local
     * cote app (ex: apres reinstallation). Tous statuts confondus, filtrable
     * par session.
     *
     * @param array<string, mixed> $args
     * @return Builder<Perimetre>
     */
    public static function getQueryPerimetreMobile(Utilisateur $agent, array $args = []): Builder
    {
        $query = Perimetre::query()->where('agent_declarant_id', $agent->id);

        if (isset($args['session_id'])) {
            $query->where('session_id', $args['session_id']);
        }

        if (isset($args['statut'])) {
            $query->where('statut', $args['statut']);
        }

        return $query->orderByDesc('declare_le');
    }

    /**
     * Verrous actifs d'une session (doc fonctionnel §6.5 : "Verrous actifs
     * d'une session"). Filtrable par statut si besoin (rarement utilise, la
     * plupart des consultations veulent les verrous actifs uniquement).
     *
     * @param array<string, mixed> $args
     * @return Builder<VerrouEmplacement>
     */
    public static function getQueryVerrouEmplacement(array $args): Builder
    {
        $query = VerrouEmplacement::query()->with('agent');

        self::scoperSitesViaSession($query);

        if (isset($args['session_id'])) {
            $query->where('session_id', $args['session_id']);
        }

        if (!empty($args['actifs_seulement'])) {
            $query->whereNull('libere_le');
        }

        return $query->orderByDesc('verrouille_le');
    }

    /**
     * Meme principe que scoperSites, mais pour les entites qui n'ont pas de
     * colonne code_site propre (Perimetre, VerrouEmplacement) : filtre via
     * la session parente.
     *
     * @param Builder<Perimetre>|Builder<VerrouEmplacement> $query
     */
    private static function scoperSitesViaSession(Builder $query): void
    {
        /** @var Utilisateur|null $acteur */
        $acteur = Auth::guard('api')->user();

        if (!$acteur) {
            return;
        }

        $codesSites = $acteur->codesSites();

        if (!empty($codesSites)) {
            $query->whereHas('session', function (Builder $q) use ($codesSites) {
                $q->whereIn('code_site', $codesSites);
            });
        }
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
