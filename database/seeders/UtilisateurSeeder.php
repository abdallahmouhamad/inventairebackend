<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Utilisateur;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de demonstration (mot de passe unique demo2026, comme documente dans
 * FRONTEND_CONTEXT.md §3.1) -- domaine @inventaire.sn retenu (clarification
 * tranchee, remplace @pna.sn utilise precedemment).
 *
 * Les codes site sont les VRAIS codes Sage X3 (recuperes via GET /sites sur
 * RererentielX3 : MC01, AG01...) pour que le scoping site des
 * INVENTORY_MANAGER de demo produise de vrais resultats une fois
 * POST /api/sessions/synchroniser-x3 declenche.
 */
class UtilisateurSeeder extends Seeder
{
    private const MOT_DE_PASSE_DEMO = 'demo2026';

    public function run(): void
    {
        $this->creerUtilisateur('Admin', 'HTSOFT', 'admin@inventaire.sn', Role::SUPER_ADMIN);

        $invMc01 = $this->creerUtilisateur('Responsable', 'Magasin Central', 'inventory.manager1@inventaire.sn', Role::INVENTORY_MANAGER);
        $invMc01->attacherSites(['MC01']);

        $invAg01 = $this->creerUtilisateur('Responsable', 'Agence Toamasina', 'inventory.manager2@inventaire.sn', Role::INVENTORY_MANAGER);
        $invAg01->attacherSites(['AG01']);

        $this->creerUtilisateur('Audit', 'Lecture Seule', 'audit@inventaire.sn', Role::READONLY);

        // Comptes mobiles (hors perimetre Web Admin, utiles pour tester le
        // rejet de connexion croisee et les futurs endpoints mobile).
        $this->creerUtilisateur('Ibrahima', 'Fall', 'operateur1@inventaire.sn', Role::OPERATOR);
        $this->creerUtilisateur('Coumba', 'Diallo', 'manager.mobile@inventaire.sn', Role::MOBILE_MANAGER);
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
