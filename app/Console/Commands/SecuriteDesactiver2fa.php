<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SecuriteDesactiver2fa extends Command
{
    protected $signature = 'securite:desactiver-2fa {email : Adresse email du compte}';

    protected $description = "Secours : retire la double authentification d'un compte (téléphone et codes de secours perdus)";

    public function handle(): int
    {
        $user = User::where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if (! $user) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
        $user->revoquerJetons();

        Audit::enregistrer('2fa_reinitialise', "Double authentification retirée en ligne de commande : {$user->name}", $user, [], null);

        $this->info("Double authentification retirée pour {$user->email}. Ses sessions ont été fermées.");

        return self::SUCCESS;
    }
}
