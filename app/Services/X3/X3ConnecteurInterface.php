<?php

namespace App\Services\X3;

/**
 * Interface d'acces a Sage X3, telle que recommandee par le document
 * fonctionnel (§7.3) : une abstraction derriere laquelle une implementation
 * reelle (RererentielX3, deja en place) ou simulee peut etre branchee, sans
 * jamais interroger SQL Server directement depuis Laravel.
 */
interface X3ConnecteurInterface
{
    /**
     * Sessions d'inventaire natives X3 (vue V_RX3_SESSIONS via RererentielX3).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererSessions(?string $codeSite = null): array;

    /**
     * Rayons d'un depot (GET /rayons?site=&depot= sur RererentielX3 --
     * FRONTEND_CONTEXT.md §2.1). Utilise pour determiner la disponibilite des
     * rayons lors de la declaration d'un perimetre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererRayons(string $codeSite, string $codeDepot): array;
}
