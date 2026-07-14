<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * RoleSeeder est structurel (les 5 roles fixes de l'app) : toujours
     * execute, y compris en production. UtilisateurSeeder/SessionInventaireSeeder
     * ne creent que des donnees de demo/dev (comptes a mot de passe partage,
     * fausses sessions) : jamais executes en production, pour que
     * `php artisan db:seed` reste la meme commande partout sans risque.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        if (!app()->environment('production')) {
            $this->call([
                UtilisateurSeeder::class,
                SessionInventaireSeeder::class,
            ]);
        }
    }
}
