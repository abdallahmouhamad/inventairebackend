<?php

namespace App\Services;

use App\Models\FicheComptage;
use App\Models\LigneComptage;
use Illuminate\Support\Collection;

/**
 * Appariement et arbitrage des lignes entre une fiche initiale et sa fiche de
 * recomptage (doc fonctionnel, FRONTEND_CONTEXT.md §3.5). Les quantites
 * saisies par les agents ne sont jamais modifiees ici (donnee source,
 * immuable comme partout ailleurs dans l'app) : seul LigneComptage::statut_review
 * (APPROVED/REJECTED) et LigneComptage::resultat_arbitrage (sur la ligne de
 * recomptage) portent le resultat de l'arbitrage. Un futur consommateur (PUSH
 * X3) devra lire resultat_arbitrage pour savoir quelle valeur retenir.
 */
class ArbitrageService
{
    /**
     * Apparie chaque ligne de la fiche de recomptage a la ligne correspondante
     * de la fiche initiale via la cle (code_emplacement, code_article,
     * numero_lot). Les paires non divergentes et les lignes sans correspondance
     * sont approuvees automatiquement (pas d'arbitrage necessaire) ; les
     * paires divergentes sont remises a PENDING sur les deux lignes, quel que
     * soit leur statut_review issu de l'examen normal precedent (celui-ci est
     * caduc : l'arbitrage repart de zero sur les lignes concernees).
     */
    public static function appairer(FicheComptage $ficheInitiale, FicheComptage $ficheRecomptage): void
    {
        $lignesInitiales = $ficheInitiale->lignes;
        $clesAppariees = [];

        foreach ($ficheRecomptage->lignes as $ligneRecomptage) {
            $ligneInitiale = $lignesInitiales->first(
                fn (LigneComptage $l) => self::cle($l) === self::cle($ligneRecomptage)
            );

            if (!$ligneInitiale) {
                $ligneRecomptage->update(['statut_review' => LigneComptage::REVIEW_APPROVED]);

                continue;
            }

            $clesAppariees[] = self::cle($ligneInitiale);
            $ligneRecomptage->update(['ligne_appariee_id' => $ligneInitiale->id]);

            if (self::estDivergente($ligneInitiale, $ligneRecomptage)) {
                $ligneInitiale->update(['statut_review' => LigneComptage::REVIEW_PENDING]);
                $ligneRecomptage->update(['statut_review' => LigneComptage::REVIEW_PENDING, 'resultat_arbitrage' => null]);
            } else {
                $ligneInitiale->update(['statut_review' => LigneComptage::REVIEW_APPROVED]);
                $ligneRecomptage->update([
                    'statut_review' => LigneComptage::REVIEW_APPROVED,
                    'resultat_arbitrage' => LigneComptage::RESULTAT_INITIALE,
                ]);
            }
        }

        foreach ($lignesInitiales as $ligneInitiale) {
            if (!in_array(self::cle($ligneInitiale), $clesAppariees, true)) {
                $ligneInitiale->update(['statut_review' => LigneComptage::REVIEW_APPROVED]);
            }
        }
    }

    public static function estDivergente(LigneComptage $initiale, LigneComptage $recomptage): bool
    {
        return (int) $initiale->qte_comptee_itu !== (int) $recomptage->qte_comptee_itu
            || (int) $initiale->qte_comptee_stu !== (int) $recomptage->qte_comptee_stu;
    }

    /**
     * Ecart entre les deux comptages independants d'une meme paire (et non
     * plus comptage vs theorique X3 comme App\Services\CalculEcart), avec des
     * seuils de criticite dedies (config('inventaire.seuil_ecart_recomptage_*')).
     *
     * @return array{itu: int, stu: int, percent: float|null, criticite: string}
     */
    public static function pourPaire(LigneComptage $initiale, LigneComptage $recomptage): array
    {
        $ecartItu = (int) $recomptage->qte_comptee_itu - (int) $initiale->qte_comptee_itu;
        $ecartStu = (int) $recomptage->qte_comptee_stu - (int) $initiale->qte_comptee_stu;

        $pourcentage = ((int) $initiale->qte_comptee_itu > 0)
            ? round(abs($ecartItu) / (int) $initiale->qte_comptee_itu * 100, 2)
            : null;

        return [
            'itu' => $ecartItu,
            'stu' => $ecartStu,
            'percent' => $pourcentage,
            'criticite' => self::criticite($pourcentage),
        ];
    }

    /**
     * Enregistre le choix de l'arbitre pour une paire divergente : la ligne
     * gagnante passe APPROVED, la perdante REJECTED (des deux cotes de la
     * paire), et resultat_arbitrage est fixe sur la ligne de recomptage.
     */
    public static function arbitrer(LigneComptage $ligneRecomptage, string $choix): void
    {
        $ligneInitiale = $ligneRecomptage->ligneAppariee;

        $ligneRecomptage->update([
            'resultat_arbitrage' => $choix,
            'statut_review' => $choix === LigneComptage::RESULTAT_RECOMPTAGE
                ? LigneComptage::REVIEW_APPROVED
                : LigneComptage::REVIEW_REJECTED,
        ]);

        $ligneInitiale?->update([
            'statut_review' => $choix === LigneComptage::RESULTAT_INITIALE
                ? LigneComptage::REVIEW_APPROVED
                : LigneComptage::REVIEW_REJECTED,
        ]);
    }

    /**
     * L'arbitrage est complet quand plus aucune ligne (des deux fiches) n'est
     * PENDING -- condition requise avant de pouvoir cloturer (doc fonctionnel :
     * "tous les choix doivent etre faits avant de valider l'arbitrage").
     */
    public static function estComplet(FicheComptage $ficheInitiale, FicheComptage $ficheRecomptage): bool
    {
        return !$ficheInitiale->lignes()->where('statut_review', LigneComptage::REVIEW_PENDING)->exists()
            && !$ficheRecomptage->lignes()->where('statut_review', LigneComptage::REVIEW_PENDING)->exists();
    }

    /**
     * Vue d'ensemble des paires pour l'ecran d'arbitrage web : chaque ligne de
     * recomptage, sa ligne initiale appariee (si trouvee) et l'ecart calcule.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function paires(FicheComptage $ficheInitiale, FicheComptage $ficheRecomptage): Collection
    {
        return $ficheRecomptage->lignes->map(function (LigneComptage $ligneRecomptage) {
            $ligneInitiale = $ligneRecomptage->ligneAppariee;

            return [
                'ligne_recomptage' => $ligneRecomptage,
                'ligne_initiale' => $ligneInitiale,
                'divergente' => $ligneInitiale ? self::estDivergente($ligneInitiale, $ligneRecomptage) : false,
                'ecart' => $ligneInitiale ? self::pourPaire($ligneInitiale, $ligneRecomptage) : null,
            ];
        });
    }

    private static function cle(LigneComptage $ligne): string
    {
        return "{$ligne->code_emplacement}|{$ligne->code_article}|{$ligne->numero_lot}";
    }

    private static function criticite(?float $pourcentage): string
    {
        if ($pourcentage === null) {
            return CalculEcart::CRITICITE_INCONNUE;
        }

        if ($pourcentage >= (float) config('inventaire.seuil_ecart_recomptage_eleve_pct')) {
            return CalculEcart::CRITICITE_CRITIQUE;
        }

        if ($pourcentage >= (float) config('inventaire.seuil_ecart_recomptage_modere_pct')) {
            return CalculEcart::CRITICITE_AVERTISSEMENT;
        }

        return CalculEcart::CRITICITE_NORMALE;
    }
}
