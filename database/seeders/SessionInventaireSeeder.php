<?php

namespace Database\Seeders;

use App\Models\SessionInventaire;
use App\Models\Utilisateur;
use App\Services\X3\ImportateurSessions;
use App\Services\X3\X3ConnecteurInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Tente d'abord un vrai PULL depuis RererentielX3 (site MC01, qui a de vraies
 * sessions cote X3) pour avoir des donnees authentiques des le premier seed.
 * Si l'API X3 est injoignable (dev hors ligne), on continue quand meme avec
 * des sessions fictives -- mais sur de VRAIS codes site (MC01, AG01), pour
 * que le scoping des INVENTORY_MANAGER de demo reste coherent avec le
 * referentiel reel.
 */
class SessionInventaireSeeder extends Seeder
{
    public function run(): void
    {
        $this->importerDepuisX3();
        $this->creerSessionsDeTest();
    }

    private function importerDepuisX3(): void
    {
        try {
            $connecteur = app(X3ConnecteurInterface::class);
            $importateur = app(ImportateurSessions::class);

            $sessionsX3 = $connecteur->recupererSessions('MC01');
            $resultat = $importateur->importer($sessionsX3);

            $this->command?->info("PULL X3 (MC01) : {$resultat['creees']} creees, {$resultat['deja_presentes']} deja presentes.");
        } catch (Throwable $e) {
            $this->command?->warn("RererentielX3 injoignable pendant le seed, sessions fictives uniquement ({$e->getMessage()}).");
        }
    }

    private function creerSessionsDeTest(): void
    {
        $invMc01 = Utilisateur::where('email', 'inv.mc01@inventaire.sn')->first();
        $operateur = Utilisateur::where('email', 'operateur1@inventaire.sn')->first();

        $session1 = SessionInventaire::firstOrCreate(
            ['code' => 'DEV-INV-001'],
            [
                'nom' => 'Inventaire chambre froide (donnee de dev)',
                'code_site' => 'MC01',
                'statut' => SessionInventaire::STATUT_OPEN,
                'date_debut' => now()->subDays(5),
                'x3_session_id' => 'DEV-X3-SESSION-001',
                'importee_de_x3_le' => now()->subDays(5),
                'ouverte_aux_agents_le' => now()->subDays(4),
                'ouverte_par' => $invMc01?->id,
            ],
        );

        $session2 = SessionInventaire::firstOrCreate(
            ['code' => 'DEV-INV-002'],
            [
                'nom' => 'Inventaire produits dangereux (donnee de dev)',
                'code_site' => 'AG01',
                'statut' => SessionInventaire::STATUT_IN_PROGRESS,
                'date_debut' => now()->subDays(10),
                'x3_session_id' => 'DEV-X3-SESSION-002',
                'importee_de_x3_le' => now()->subDays(10),
                'ouverte_aux_agents_le' => now()->subDays(9),
                'total_lignes' => 240,
                'lignes_soumises' => 87,
                'lignes_validees' => 40,
            ],
        );

        // Agent autorise sur les deux sessions de test, pour pouvoir tester
        // GET /api/mobile/sessions des le premier seed.
        if ($operateur) {
            $session1->utilisateursAutorises()->syncWithoutDetaching([$operateur->id]);
            $session2->utilisateursAutorises()->syncWithoutDetaching([$operateur->id]);
        }
    }
}
