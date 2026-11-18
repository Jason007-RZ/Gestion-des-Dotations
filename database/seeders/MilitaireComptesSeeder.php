<?php

namespace Database\Seeders;

use App\Models\Militaire;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crée un compte de connexion (rôle "militaire") pour chaque fiche Militaire
 * qui n'en a pas encore. Rejouable sans risque : une fiche déjà liée à un
 * compte est ignorée, donc aucun doublon et aucune donnée effacée.
 *
 * Lancer seul : php artisan db:seed --class=MilitaireComptesSeeder
 * Identifiants de démonstration : <matricule en minuscules>@dotations.mil / password
 */
class MilitaireComptesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Seeder de démonstration interdit en production (comptes à mot de passe connu).');
        }

        // Un seul hash réutilisé pour tous les comptes : bcrypt est lent
        // et hasher 100 fois le même mot de passe ralentirait inutilement.
        $motDePasse = Hash::make('password');

        $dejaLies = User::whereNotNull('militaire_id')->pluck('militaire_id')->all();

        $crees = 0;

        Militaire::with('grade')->whereNotIn('id', $dejaLies)->orderBy('id')->each(function (Militaire $militaire) use ($motDePasse, &$crees) {
            $email = strtolower($militaire->matricule) . '@dotations.mil';

            // Si l'email existe déjà (compte créé à la main), on n'écrase rien.
            if (User::where('email', $email)->exists()) {
                return;
            }

            $user = new User();
            $user->forceFill([
                'name' => trim(($militaire->grade?->libelle ?? '') . ' ' . $militaire->nom . ' ' . $militaire->prenoms),
                'email' => $email,
                'password' => $motDePasse,
                'role' => User::ROLE_MILITAIRE,
                'militaire_id' => $militaire->id,
            ])->save();

            $crees++;
        });

        $this->command?->info("Comptes militaires créés : {$crees} (total militaires : " . Militaire::count() . ').');
    }
}
