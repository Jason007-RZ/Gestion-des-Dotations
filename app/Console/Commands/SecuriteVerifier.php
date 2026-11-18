<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class SecuriteVerifier extends Command
{
    protected $signature = 'securite:verifier {--purger-jetons : Supprime les jetons sans date d\'expiration (déconnecte ces sessions)}';

    protected $description = 'Contrôle la configuration de sécurité : environnement, CORS, mots de passe par défaut, 2FA, jetons';

    private const OK = 'OK';

    private const ATTENTION = 'ATTENTION';

    private const ECHEC = 'ECHEC';

    /** Mots de passe de démonstration les plus courants, testés contre les comptes existants. */
    private const MOTS_DE_PASSE_DEMO = ['password', 'admin', '12345678', 'motdepasse'];

    /** @var list<array{0:string,1:string,2:string}> */
    private array $lignes = [];

    public function handle(): int
    {
        $prod = app()->isProduction();

        $this->controlerEnvironnement($prod);
        $this->controlerBaseDeDonnees($prod);
        $this->controlerCors();
        $this->controlerJetons();
        $this->controlerComptes($prod);

        $this->table(['Résultat', 'Contrôle', 'Détail'], $this->lignes);

        $echecs = count(array_filter($this->lignes, fn ($l) => $l[0] === self::ECHEC));
        $attentions = count(array_filter($this->lignes, fn ($l) => $l[0] === self::ATTENTION));

        $this->newLine();
        $this->line("Bilan : {$echecs} échec(s), {$attentions} avertissement(s).");

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ajouter(string $statut, string $controle, string $detail): void
    {
        $this->lignes[] = [$statut, $controle, $detail];
    }

    private function controlerEnvironnement(bool $prod): void
    {
        $this->ajouter(self::OK, 'Environnement', 'APP_ENV='.config('app.env'));

        if (! config('app.key')) {
            $this->ajouter(self::ECHEC, 'APP_KEY', 'Absente : php artisan key:generate');
        } else {
            $this->ajouter(self::OK, 'APP_KEY', 'Définie');
        }

        if (config('app.debug')) {
            $this->ajouter($prod ? self::ECHEC : self::ATTENTION, 'APP_DEBUG', $prod
                ? 'true en production : les erreurs affichent le code, les chemins et la configuration. Mettre false.'
                : 'true : normal en développement, à passer à false en production.');
        } else {
            $this->ajouter(self::OK, 'APP_DEBUG', 'false');
        }

        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->ajouter($https ? self::OK : ($prod ? self::ECHEC : self::ATTENTION), 'HTTPS (APP_URL)', $https
            ? 'APP_URL en https'
            : 'APP_URL sans https : en production, tout doit passer par HTTPS (mots de passe et jetons en clair sinon).');

        if ($prod && config('logging.channels.'.config('logging.default').'.level', 'debug') === 'debug') {
            $this->ajouter(self::ATTENTION, 'LOG_LEVEL', 'debug en production : trop bavard, mettre warning ou error.');
        }
    }

    private function controlerBaseDeDonnees(bool $prod): void
    {
        $connexion = config('database.connections.'.config('database.default'), []);
        $utilisateur = $connexion['username'] ?? null;

        if ($utilisateur === 'root') {
            $this->ajouter($prod ? self::ECHEC : self::ATTENTION, 'Base de données', 'Connexion avec le compte root : créer un compte dédié avec les seuls droits nécessaires.');
        } else {
            $this->ajouter(self::OK, 'Base de données', 'Compte dédié ('.($utilisateur ?? 'sqlite').')');
        }

        if ($utilisateur && ($connexion['password'] ?? '') === '') {
            $this->ajouter($prod ? self::ECHEC : self::ATTENTION, 'Mot de passe de la base', 'Vide : définir un mot de passe fort.');
        }

        if (! Schema::hasTable('audit_logs')) {
            $this->ajouter(self::ECHEC, "Journal d'audit", 'Table absente : php artisan migrate');
        } else {
            $this->ajouter(self::OK, "Journal d'audit", 'Table présente');
        }
    }

    private function controlerCors(): void
    {
        $origines = (array) config('cors.allowed_origins', []);

        if (in_array('*', $origines, true)) {
            $this->ajouter(self::ECHEC, 'CORS', "« * » autorise n'importe quel site : lister l'adresse exacte du frontend dans CORS_ALLOWED_ORIGINS.");
        } else {
            $this->ajouter(self::OK, 'CORS', count($origines).' origine(s) autorisée(s)');
        }
    }

    private function controlerJetons(): void
    {
        $duree = (int) config('securite.jeton_duree_minutes');
        $this->ajouter($duree > 0 && $duree <= 1440 ? self::OK : self::ATTENTION, 'Durée des jetons', "{$duree} min (inactivité : ".config('securite.inactivite_minutes').' min)');

        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $sansExpiration = PersonalAccessToken::whereNull('expires_at')->count();

        if ($sansExpiration > 0 && $this->option('purger-jetons')) {
            PersonalAccessToken::whereNull('expires_at')->delete();
            $this->ajouter(self::OK, 'Anciens jetons', "{$sansExpiration} jeton(s) sans expiration supprimé(s) (reconnexion nécessaire).");
        } elseif ($sansExpiration > 0) {
            $this->ajouter(self::ATTENTION, 'Anciens jetons', "{$sansExpiration} jeton(s) sans date d'expiration (créés avant ce durcissement). Option --purger-jetons pour les supprimer.");
        } else {
            $this->ajouter(self::OK, 'Anciens jetons', 'Tous les jetons expirent');
        }
    }

    private function controlerComptes(bool $prod): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $admins = User::where('role', User::ROLE_ADMIN)->get();

        if ($admins->isEmpty()) {
            $this->ajouter(self::ECHEC, 'Administrateurs', 'Aucun : php artisan securite:creer-admin');
        } elseif ($admins->count() === 1) {
            $this->ajouter(self::ATTENTION, 'Administrateurs', 'Un seul compte administrateur : prévoir un second compte de secours.');
        } else {
            $this->ajouter(self::OK, 'Administrateurs', $admins->count().' comptes');
        }

        $sans2fa = $admins->filter(fn (User $u) => ! $u->aDoubleFacteur())->pluck('email')->all();
        $this->ajouter($sans2fa ? self::ATTENTION : self::OK, 'Double authentification (admins)', $sans2fa
            ? 'Non activée pour : '.implode(', ', $sans2fa).' (écran « Sécurité du compte »).'
            : 'Activée pour tous les administrateurs');

        // Mots de passe de démonstration : un seul test par empreinte distincte (les comptes créés en masse en partagent une).
        $this->info('Recherche de mots de passe de démonstration (quelques secondes)...');
        $parEmpreinte = User::orderBy('id')->get(['id', 'email', 'password', 'role'])->groupBy('password');
        $faibles = [];
        foreach ($parEmpreinte as $empreinte => $comptes) {
            foreach (self::MOTS_DE_PASSE_DEMO as $candidat) {
                if (Hash::check($candidat, (string) $empreinte)) {
                    foreach ($comptes as $c) {
                        $faibles[] = $c->email;
                    }
                    break;
                }
            }
        }

        if ($faibles) {
            $apercu = implode(', ', array_slice($faibles, 0, 5)).(count($faibles) > 5 ? '... (+'.(count($faibles) - 5).')' : '');
            $this->ajouter($prod ? self::ECHEC : self::ATTENTION, 'Mots de passe de démonstration', count($faibles)." compte(s) : {$apercu}. ".($prod ? 'À changer immédiatement.' : 'Acceptable en développement, jamais en production.'));
        } else {
            $this->ajouter(self::OK, 'Mots de passe de démonstration', 'Aucun compte avec un mot de passe courant');
        }
    }
}
