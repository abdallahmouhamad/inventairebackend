<?php

namespace App\Services\X3;

use App\Models\SessionInventaire;
use App\Support\Outils;
use Carbon\Carbon;

/**
 * Mapping X3 (numero_session, description, code_site, date_session...) vers
 * sessions_inventaire. Utilise a la fois par SessionInventaireController
 * (PULL declenche par l'utilisateur) et SessionInventaireSeeder (donnees de
 * dev), pour ne pas dupliquer la logique de conversion.
 */
class ImportateurSessions
{
    /**
     * N'ecrase jamais une session deja presente localement (match sur
     * x3_session_id) -- idempotent, voir doc fonctionnel §7.4.
     *
     * @param array<int, array<string, mixed>> $sessionsX3
     * @return array{creees: int, deja_presentes: int}
     */
    public function importer(array $sessionsX3): array
    {
        $creees = 0;
        $ignorees = 0;

        foreach ($sessionsX3 as $sessionX3) {
            $numeroSession = $sessionX3['numero_session'] ?? null;

            if (!$numeroSession) {
                continue;
            }

            if (SessionInventaire::where('x3_session_id', $numeroSession)->exists()) {
                $ignorees++;

                continue;
            }

            $dateSession = Outils::nettoyerDateX3($sessionX3['date_session'] ?? null);

            SessionInventaire::create([
                'code' => $numeroSession,
                'nom' => $sessionX3['description'] ?? $numeroSession,
                'code_site' => $sessionX3['code_site'] ?? '',
                'statut' => SessionInventaire::STATUT_IMPORTED_FROM_X3,
                'date_debut' => $dateSession ? Carbon::parse($dateSession) : now(),
                'x3_session_id' => $numeroSession,
                'importee_de_x3_le' => now(),
            ]);

            $creees++;
        }

        return ['creees' => $creees, 'deja_presentes' => $ignorees];
    }
}
