<?php

namespace App\Support;

use GraphQL\Type\Definition\Type;
use Illuminate\Http\JsonResponse;
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
     */
    public static function reponseErreur(Throwable $e, int $statut = 422): JsonResponse
    {
        $payload = [
            'errors' => [config('app.debug') ? $e->getMessage() : 'Une erreur est survenue.'],
        ];

        if (config('app.debug')) {
            $payload['errors_debug'] = [$e->getMessage()];
            $payload['errors_line'] = [$e->getLine()];
        }

        return response()->json($payload, $statut);
    }
}
