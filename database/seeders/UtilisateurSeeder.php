<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\Utilisateur;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de demonstration (mot de passe unique demo2026, comme documente dans
 * FRONTEND_CONTEXT.md §5) -- domaine @pna.sn retenu suite a l'arbitrage sur
 * l'incoherence README (@pna.sn) vs mock front (@inventaire.com).
 */
class UtilisateurSeeder extends Seeder
{
    private const MOT_DE_PASSE_DEMO = 'demo2026';

    public function run(): void
    {
        $siteMagasinCentral = Site::where('code', 'site-1')->first();
        $sitePra = Site::where('code', 'site-2')->first();

        $superAdmin = $this->creerUtilisateur('Admin', 'PNA', 'admin@pna.sn', Role::SUPER_ADMIN);

        $invMcd = $this->creerUtilisateur('Responsable', 'Magasin Central', 'inv.mcd@pna.sn', Role::INVENTORY_MANAGER);
        if ($siteMagasinCentral) {
            $invMcd->sites()->syncWithoutDetaching([$siteMagasinCentral->id]);
        }

        $invDkr = $this->creerUtilisateur('Responsable', 'PRA Dakar', 'inv.dkr@pna.sn', Role::INVENTORY_MANAGER);
        if ($sitePra) {
            $invDkr->sites()->syncWithoutDetaching([$sitePra->id]);
        }

        $this->creerUtilisateur('Audit', 'Lecture Seule', 'audit@pna.sn', Role::READONLY);

        // Comptes mobiles (hors perimetre Web Admin, utiles pour tester le
        // rejet de connexion croisee et les futurs endpoints mobile).
        $this->creerUtilisateur('Ibrahima', 'Fall', 'operateur1@pna.sn', Role::OPERATOR);
        $this->creerUtilisateur('Coumba', 'Diallo', 'manager.mobile@pna.sn', Role::MOBILE_MANAGER);
    }

    private function creerUtilisateur(string $prenom, string $nom, string $email, string $codeRole): Utilisateur
    {
        $role = Role::where('code', $codeRole)->firstOrFail();

        return Utilisateur::firstOrCreate(
            ['email' => $email],
            [
                'prenom' => $prenom,
                'nom' => $nom,
                'mot_de_passe' => Hash::make(self::MOT_DE_PASSE_DEMO),
                'role_id' => $role->id,
                'est_actif' => true,
            ],
        );
    }
}
