<?php

return [

    /*
     * Parametres systeme documentes au doc fonctionnel §6.7/§9.2. Pas encore
     * de module Settings editable (Phase 6, pas construite) -- en attendant,
     * ce sont de simples constantes overridables par env, pas stockees en
     * base. A migrer vers une vraie table `parametres` le jour ou l'ecran
     * Web Admin de parametrage est construit.
     */
    'timeout_verrou_minutes' => env('TIMEOUT_VERROU_MINUTES', 15),

    /*
     * Seuils d'ecart (doc fonctionnel §6.7/§9.3), en pourcentage de la
     * quantite theorique. En dessous du seuil avertissement : normal.
     * Entre les deux : a surveiller. Au-dessus du seuil critique : critique.
     */
    'seuil_ecart_avertissement_pct' => env('SEUIL_ECART_AVERTISSEMENT_PCT', 5),
    'seuil_ecart_critique_pct' => env('SEUIL_ECART_CRITIQUE_PCT', 15),

    /*
     * Seuils d'ecart specifiques au recomptage/arbitrage (FRONTEND_CONTEXT.md
     * §3.9 : recountModerateCriticalityThresholdPct/recountHighCriticalityThresholdPct),
     * volontairement distincts et plus stricts que les seuils normaux
     * ci-dessus -- un ecart entre deux comptages independants d'un meme
     * emplacement est plus significatif qu'un ecart comptage/theorique X3.
     */
    'seuil_ecart_recomptage_modere_pct' => env('SEUIL_ECART_RECOMPTAGE_MODERE_PCT', 2),
    'seuil_ecart_recomptage_eleve_pct' => env('SEUIL_ECART_RECOMPTAGE_ELEVE_PCT', 10),

];
