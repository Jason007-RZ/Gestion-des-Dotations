<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\MotDePasse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SecuriteCreerAdmin extends Command
{
    protected $signature = 'securite:creer-admin {email : Adresse email du compte} {--name=Administrateur : Nom affiché}';

    protected $description = 'Crée un compte administrateur avec un mot de passe respectant la politique de sécurité';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $nom = (string) $this->option('name');

        if (User::where('email', $email)->exists()) {
            $this->error("Un compte existe déjà avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $this->line('Mot de passe : '.config('securite.mdp_min').' caractères minimum (une phrase de passe convient très bien).');
        $mdp = (string) $this->secret('Mot de passe');
        $confirmation = (string) $this->secret('Confirmer le mot de passe');

        if ($mdp !== $confirmation) {
            $this->error('Les deux saisies sont différentes.');

            return self::FAILURE;
        }

        $validation = Validator::make(
            ['email' => $email, 'name' => $nom, 'password' => $mdp],
            ['email' => ['required', 'email', 'max:255'], 'password' => MotDePasse::regles([$email, $nom])]
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $nom,
            'email' => $email,
            'password' => Hash::make($mdp),
            'role' => User::ROLE_ADMIN,
        ]);

        Audit::enregistrer('utilisateur_cree', "Compte administrateur créé en ligne de commande : {$email}", $user, ['role' => User::ROLE_ADMIN], null);

        $this->info("Administrateur créé : {$email}. Activez la double authentification depuis « Sécurité du compte ».");

        return self::SUCCESS;
    }
}
