<?php

namespace App\Support;

use GraphQL\Type\Definition\Type;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fonctions reutilisables/centrales appelees depuis plusieurs endroits de l'app
 * (types, queries GraphQL, controleurs...), pour eviter la duplication.
 */
class Outils
{
    /**
     * Arguments de pagination communs a toutes les *PaginatedQuery GraphQL.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function paginationArgs(): array
    {
        return [
            'page' => [
                'type' => Type::int(),
                'description' => 'Numero de page (defaut : 1)',
            ],
            'count' => [
                'type' => Type::int(),
                'description' => "Nombre d'elements par page (defaut : 15)",
            ],
        ];
    }

    /**
     * Reponse d'erreur JSON uniforme pour les controleurs REST (memes cles que
     * les autres backends de l'entreprise : errors / errors_debug / errors_line).
     *
     * Contrairement a la convention d'origine, errors_debug et errors_line ne
     * sont exposes que lorsque APP_DEBUG=true : ne jamais divulguer le message
     * d'exception brut ni le numero de ligne a un client en production.
     *
     * Journalise egalement l'exception (sauf 401/403/422, routiniers et non
     * actionnables) : comme cette methode absorbe l'exception au lieu de la
     * laisser remonter au handler global de Laravel, sans ce Log::error() rien
     * n'apparaissait jamais dans storage/logs/laravel.log -- un vrai bug etait
     * devenu indiagnostiquable en production sans acces SSH + tinker.
     */
    public static function reponseErreur(Throwable $e, int $statut = 422): JsonResponse
    {
        if (!in_array($statut, [401, 403, 422], true)) {
            Log::error("[{$statut}] {$e->getMessage()}", ['exception' => $e]);
        }

        $payload = [
            'errors' => [config('app.debug') ? $e->getMessage() : 'Une erreur est survenue.'],
        ];

        if (config('app.debug')) {
            $payload['errors_debug'] = [$e->getMessage()];
            $payload['errors_line'] = [$e->getLine()];
        }

        return response()->json($payload, $statut);
    }

    /**
     * Nettoie une date brute issue de Sage X3 (via RererentielX3) : les dates
     * "nulles" y sont representees par des sentinelles SQL Server
     * (1753-01-01, date minimale DATETIME ; 1899-12-31, epoque OLE/Delphi),
     * jamais par un vrai null JSON. A appeler sur tout champ date_* provenant
     * de l'API X3 avant de le stocker ou de l'exposer.
     */
    public static function nettoyerDateX3(?string $valeur): ?string
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        if (str_starts_with($valeur, '1753-01-01') || str_starts_with($valeur, '1899-12-31')) {
            return null;
        }

        return $valeur;
    }
}
