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

];
