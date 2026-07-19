<?php

namespace App\Services;

use App\Models\LigneComptage;

/**
 * Calcul de l'ecart et de sa criticite pour une ligne de comptage (doc
 * fonctionnel §9.3). Centralise ici plutot que disperse dans les
 * controleurs/serialisations (recommandation §10.2 du doc fonctionnel).
 *
 * Le pourcentage se base sur l'ITU (unite d'inventaire), meme convention que
 * la formule de divergence utilisee pour l'arbitrage (FRONTEND_CONTEXT.md) --
 * pas de valeur theorique en ITU (article hors-liste par exemple) => percent
 * null, seul l'ecart absolu reste exploitable.
 */
class CalculEcart
{
    public const CRITICITE_NORMALE = 'normale';

    public const CRITICITE_AVERTISSEMENT = 'avertissement';

    public const CRITICITE_CRITIQUE = 'critique';

    public const CRITICITE_INCONNUE = 'inconnue';

    /**
     * @return array{itu: int, stu: int, percent: float|null, criticite: string}
     */
    public static function pour(LigneComptage $ligne): array
    {
        $ecartItu = $ligne->qte_comptee_itu - (int) ($ligne->qte_theorique_itu ?? 0);
        $ecartStu = $ligne->qte_comptee_stu - (int) ($ligne->qte_theorique_stu ?? 0);

        $pourcentage = (!empty($ligne->qte_theorique_itu))
            ? round(abs($ecartItu) / $ligne->qte_theorique_itu * 100, 2)
            : null;

        return [
            'itu' => $ecartItu,
            'stu' => $ecartStu,
            'percent' => $pourcentage,
            'criticite' => self::criticite($pourcentage),
        ];
    }

    private static function criticite(?float $pourcentage): string
    {
        if ($pourcentage === null) {
            return self::CRITICITE_INCONNUE;
        }

        if ($pourcentage >= (float) config('inventaire.seuil_ecart_critique_pct')) {
            return self::CRITICITE_CRITIQUE;
        }

        if ($pourcentage >= (float) config('inventaire.seuil_ecart_avertissement_pct')) {
            return self::CRITICITE_AVERTISSEMENT;
        }

        return self::CRITICITE_NORMALE;
    }
}
