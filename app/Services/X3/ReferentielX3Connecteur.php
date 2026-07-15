<?php

namespace App\Services\X3;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Implementation reelle du connecteur X3, via l'API PHP "RererentielX3" deja
 * en place (lecture seule, vues SQL sur le schema X3 -- voir
 * Docs/FRONTEND_CONTEXT.md §2 pour le contrat observe).
 */
class ReferentielX3Connecteur implements X3ConnecteurInterface
{
    public function recupererSessions(?string $codeSite = null): array
    {
        return $this->appeler('/sessions', array_filter(['site' => $codeSite]));
    }

    public function recupererRayons(string $codeSite, string $codeDepot): array
    {
        return $this->appeler('/rayons', ['site' => $codeSite, 'depot' => $codeDepot]);
    }

    /**
     * @param array<string, mixed> $parametres
     * @return array<int, array<string, mixed>>
     */
    private function appeler(string $chemin, array $parametres = []): array
    {
        try {
            $reponse = Http::baseUrl(config('services.referentielx3.base_url'))
                ->timeout((int) config('services.referentielx3.timeout', 15))
                ->get($chemin, $parametres);
        } catch (\Throwable $e) {
            throw new RuntimeException("RererentielX3 injoignable ({$chemin}) : {$e->getMessage()}", previous: $e);
        }

        if ($reponse->failed()) {
            throw new RuntimeException("RererentielX3 a renvoye une erreur HTTP {$reponse->status()} sur {$chemin}.");
        }

        $corps = $reponse->json();

        if (!is_array($corps) || !($corps['success'] ?? false)) {
            $message = is_array($corps) ? ($corps['message'] ?? 'reponse invalide') : 'reponse non-JSON';
            throw new RuntimeException("RererentielX3 : {$message} ({$chemin}).");
        }

        return $corps['data'] ?? [];
    }
}
