<?php

namespace App\Services\X3;

use App\Support\Outils;
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

    public function recupererSites(): array
    {
        return $this->appeler('/sites');
    }

    public function recupererDepots(?string $codeSite = null): array
    {
        return $this->appeler('/depots', array_filter(['site' => $codeSite]));
    }

    public function recupererDetailRayon(string $codeSite, string $codeDepot, string $codeRayon, int $page = 1, int $perPage = 200): array
    {
        $corps = $this->requete("/rayons/{$codeRayon}/detail", [
            'site' => $codeSite,
            'depot' => $codeDepot,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $pagination = $corps['pagination'] ?? null;

        if (is_array($pagination) && isset($pagination['page'], $pagination['last_page'])) {
            $pagination['has_more'] = (bool) ($pagination['page'] < $pagination['last_page']);
        }

        $lignes = array_map(function (array $ligne) {
            if (array_key_exists('date_peremption', $ligne)) {
                $ligne['date_peremption'] = Outils::nettoyerDateX3($ligne['date_peremption']);
            }

            return $ligne;
        }, $corps['data'] ?? []);

        return [
            'data' => $lignes,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array<string, mixed> $parametres
     * @return array<int, array<string, mixed>>
     */
    private function appeler(string $chemin, array $parametres = []): array
    {
        return $this->requete($chemin, $parametres)['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $parametres
     * @return array<string, mixed>
     */
    private function requete(string $chemin, array $parametres = []): array
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

        return $corps;
    }
}
