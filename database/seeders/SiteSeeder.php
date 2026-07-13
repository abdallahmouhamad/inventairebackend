<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Sites de test en attendant le connecteur Sage X3 reel (§7 doc fonctionnel) :
 * le referentiel provient normalement d'un PULL X3, ceci ne fait que fournir
 * des donnees de developpement/demo coherentes avec FRONTEND_CONTEXT.md.
 */
class SiteSeeder extends Seeder
{
    public function run(): void
    {
        Site::firstOrCreate(
            ['code' => 'site-1'],
            ['nom' => 'Magasin Central Dakar', 'ville' => 'Dakar', 'est_actif' => true],
        );

        Site::firstOrCreate(
            ['code' => 'site-2'],
            ['nom' => 'PRA Dakar', 'ville' => 'Dakar', 'est_actif' => true],
        );
    }
}
