<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Militaire;
use App\Models\User;
use App\Support\Audit;
use App\Support\DoubleFacteur;
use App\Support\MotDePasse;
use App\Support\MotsDePasseFaibles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Empreinte bcrypt (coût 12) d'un mot de passe qui n'appartient à personne. Elle est comparée quand
     * l'email est inconnu, pour que le temps de réponse soit le même que pour un vrai compte
     * (sinon on pourrait deviner quels emails existent en mesurant la durée).
     */
    private const FAUX_HASH = '$2y$12$iZy.GdIwpnOhy8H9VWJWze6vSiRwwR3PxPArP2LtCIFBGnHZGhrDy';

    // ------------------------------------------------------------------ connexion

    /**
     * POST /api/login
     * Étape 1 : email + mot de passe. Si le compte a la double authentification, aucun jeton n'est
     * délivré ici : la réponse contient un « défi » à renvoyer avec le code à /api/login/2fa.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $ip = (string) $request->ip();

        $this->verifierBlocage($email, $ip);

        $user = User::where('email', $email)->first();

        // Le hachage est toujours calculé, que le compte existe ou non.
        $motDePasseOk = Hash::check($credentials['password'], $user?->password ?? self::FAUX_HASH);

        if (! $user || ! $motDePasseOk) {
            $this->enregistrerEchec($email, $ip);
            Audit::enregistrer('connexion_echec', 'Échec de connexion : '.$email, null, ['email' => $email], $user);

            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        // Le coût de hachage a pu être augmenté depuis la création du compte : on met l'empreinte à niveau.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
        }

        if ($user->aDoubleFacteur()) {
            $defi = Str::random(48);
            Cache::put(
                '2fa-defi:'.hash('sha256', $defi),
                ['user_id' => $user->id, 'essais' => 0],
                now()->addMinutes((int) config('securite.double_facteur.defi_minutes', 5))
            );

            return response()->json(['two_factor' => true, 'defi' => $defi]);
        }

        $this->effacerEchecs($email, $ip);

        return $this->ouvrirSession($user, 'connexion', "Connexion : {$user->name} ({$user->role})");
    }

    /**
     * POST /api/login/2fa
     * Étape 2 : code de l'application d'authentification (ou code de secours à usage unique).
     */
    public function loginDoubleFacteur(Request $request): JsonResponse
    {
        $data = $request->validate([
            'defi' => ['required', 'string', 'size:48'],
            'code' => ['nullable', 'string', 'max:12'],
            'code_secours' => ['nullable', 'string', 'max:20'],
        ]);

        if (empty($data['code']) && empty($data['code_secours'])) {
            throw ValidationException::withMessages(['code' => ["Saisissez le code de votre application d'authentification."]]);
        }

        $cle = '2fa-defi:'.hash('sha256', $data['defi']);
        $defi = Cache::get($cle);
        $expire = fn () => ValidationException::withMessages(['code' => ['La vérification a expiré. Reconnectez-vous.']]);

        if (! $defi) {
            throw $expire();
        }

        $user = User::find($defi['user_id']);
        if (! $user || ! $user->aDoubleFacteur()) {
            Cache::forget($cle);
            throw $expire();
        }

        $email = Str::lower($user->email);
        $ip = (string) $request->ip();
        $this->verifierBlocage($email, $ip);

        $methode = DoubleFacteur::valider($user, $data['code'] ?? null, $data['code_secours'] ?? null);

        if ($methode === null) {
            $defi['essais']++;
            $this->enregistrerEchec($email, $ip);
            Audit::enregistrer('connexion_echec', 'Échec du second facteur : '.$user->email, $user, [], $user);

            if ($defi['essais'] >= (int) config('securite.double_facteur.essais_par_defi', 5)) {
                Cache::forget($cle);
                throw ValidationException::withMessages(['code' => ["Trop d'erreurs. Reconnectez-vous."]]);
            }

            Cache::put($cle, $defi, now()->addMinutes((int) config('securite.double_facteur.defi_minutes', 5)));
            throw ValidationException::withMessages(['code' => ['Code incorrect.']]);
        }

        Cache::forget($cle);
        $this->effacerEchecs($email, $ip);

        if ($methode === DoubleFacteur::SECOURS) {
            Audit::enregistrer('code_secours_utilise', "Code de secours utilisé : {$user->name}", $user, ['restants' => count($user->two_factor_recovery_codes ?? [])], $user);
        }

        return $this->ouvrirSession($user, 'connexion', "Connexion (double authentification) : {$user->name} ({$user->role})");
    }

    public function logout(Request $request): JsonResponse
    {
        $jeton = $request->user()->currentAccessToken();
        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('unite', 'militaire.grade', 'militaire.unite');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'unite' => $user->unite,
            'militaire' => $user->militaire,
            'magasins' => $user->estMagasinier() ? $user->magasins()->get(['magasins.id', 'libelle']) : [],
            'deux_facteurs' => $user->aDoubleFacteur(),
        ]);
    }

    // ------------------------------------------------------------------ gestion du compte

    /**
     * PUT /api/me/password — changement de mot de passe par l'utilisateur lui-même.
     * Les autres appareils connectés sont déconnectés.
     */
    public function changerMotDePasse(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => [...MotDePasse::regles([$user->email, $user->name]), 'confirmed', 'different:current_password'],
        ]);

        $email = Str::lower($user->email);
        $ip = (string) $request->ip();
        $this->verifierBlocage($email, $ip);

        if (! Hash::check($data['current_password'], $user->password)) {
            $this->enregistrerEchec($email, $ip);
            throw ValidationException::withMessages(['current_password' => ['Mot de passe actuel incorrect.']]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->revoquerJetons(garderCourant: true);
        Audit::enregistrer('mot_de_passe_change', "Mot de passe modifié : {$user->name}", $user);

        return response()->json(['message' => 'Mot de passe modifié. Vos autres appareils ont été déconnectés.']);
    }

    /** POST /api/me/sessions/revoquer — déconnecte tous les autres appareils. */
    public function revoquerAutresSessions(Request $request): JsonResponse
    {
        $request->user()->revoquerJetons(garderCourant: true);
        Audit::enregistrer('sessions_revoquees', "Autres sessions révoquées : {$request->user()->name}", $request->user());

        return response()->json(['message' => 'Les autres appareils ont été déconnectés.']);
    }

    // ------------------------------------------------------------------ enrôlement et réinitialisation

    /**
     * POST /api/register
     * Enrôlement en libre-service : un militaire crée son compte de connexion, mais uniquement s'il
     * correspond à une fiche déjà existante (créée par l'administrateur) et pas encore liée à un compte.
     * On ne crée JAMAIS de fiche Militaire ici — seulement le compte qui s'y relie.
     *
     * Toutes les causes de refus (matricule inconnu, date fausse, compte déjà créé, email pris) donnent
     * le MÊME message : on ne révèle ni quels matricules ni quels emails existent. Les essais sont limités
     * par matricule, pour qu'on ne puisse pas deviner la date d'incorporation par force brute.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'matricule' => ['required', 'string', 'max:50'],
            'date_incorporation' => ['required', 'date_format:Y-m-d'], // sert de vérification d'identité
            'email' => ['required', 'email', 'max:255'],
            'password' => [...MotDePasse::regles(), 'confirmed'],
        ]);

        $matricule = trim($data['matricule']);
        $email = Str::lower(trim($data['email']));
        $cle = 'enrolement:'.sha1(Str::lower($matricule));

        if (RateLimiter::tooManyAttempts($cle, 5)) {
            $secondes = RateLimiter::availableIn($cle);
            abort(429, 'Trop de tentatives pour ce matricule. Contactez votre administrateur.', ['Retry-After' => $secondes]);
        }

        $militaire = Militaire::where('matricule', $matricule)->first();

        $refuse = ! $militaire
            || ! $militaire->estActif() // muté ou radié : pas de nouveau compte
            || $militaire->date_incorporation->format('Y-m-d') !== $data['date_incorporation']
            || User::where('militaire_id', $militaire->id)->exists()
            || User::where('email', $email)->exists();

        if ($refuse) {
            RateLimiter::hit($cle, 3600);
            Audit::enregistrer('enrolement_refuse', 'Enrôlement refusé : matricule '.$matricule, null, ['matricule' => $matricule, 'email' => $email]);

            throw ValidationException::withMessages([
                'matricule' => ["Enrôlement impossible avec ces informations. Vérifiez-les ou contactez votre administrateur."],
            ]);
        }

        if ($raison = MotsDePasseFaibles::raison($data['password'], [$militaire->nom, $militaire->prenoms, $email])) {
            throw ValidationException::withMessages(['password' => [$raison]]);
        }

        $user = User::create([
            'name' => trim(($militaire->grade?->libelle ?? '').' '.$militaire->nom.' '.$militaire->prenoms),
            'email' => $email,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_MILITAIRE,
            'militaire_id' => $militaire->id,
        ]);

        RateLimiter::clear($cle);

        return $this->ouvrirSession($user, 'enrolement', "Enrôlement : {$user->name}", 201);
    }

    /**
     * POST /api/forgot-password
     * Envoie un lien de réinitialisation (via le canal mail configuré dans .env —
     * en développement, MAIL_MAILER=log écrit le lien dans storage/logs/laravel.log).
     * Réponse volontairement identique que l'email existe ou non, pour ne pas
     * révéler quels emails sont enregistrés.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        try {
            Password::sendResetLink(['email' => Str::lower(trim($request->input('email')))]);
        } catch (\Throwable $e) {
            // Panne d'envoi (SMTP injoignable, mot de passe d'application refusé...) : consignée dans les logs,
            // mais jamais révélée. Sinon un compte existant donnerait une erreur 500 et un compte inconnu une
            // réponse normale, ce qui trahirait les adresses enregistrées.
            report($e);
        }

        return response()->json([
            'message' => "Si cette adresse est enregistrée, un lien de réinitialisation vient d'être envoyé.",
        ]);
    }

    /**
     * POST /api/reset-password
     * Finalise la réinitialisation à partir du token reçu par email.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [...MotDePasse::regles(), 'confirmed'],
        ]);
        $data['email'] = Str::lower(trim($data['email']));

        $statut = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $user->revoquerJetons(); // révoque tous les anciens accès par sécurité
            Audit::enregistrer('mot_de_passe_reinitialise', "Mot de passe réinitialisé : {$user->name}", $user, [], $user);
        });

        if ($statut !== Password::PASSWORD_RESET) {
            // Même message pour un jeton invalide, expiré ou un email inconnu : on ne révèle pas quels comptes existent.
            throw ValidationException::withMessages([
                'email' => ['Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande de lien.'],
            ]);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    // ------------------------------------------------------------------ outils

    /**
     * Délivre un jeton d'accès à durée de vie limitée. Les jetons expirés sont purgés, et le nombre
     * d'appareils connectés simultanément est plafonné (les plus anciens sont révoqués).
     */
    private function ouvrirSession(User $user, string $action, string $resume, int $statut = 200): JsonResponse
    {
        $max = max(1, (int) config('securite.jetons_max_par_compte', 5));

        $user->tokens()->where('expires_at', '<', now())->delete();
        $existants = $user->tokens()->orderByDesc('id')->pluck('id');
        if ($existants->count() >= $max) {
            $user->tokens()->whereIn('id', $existants->slice($max - 1)->values())->delete();
        }

        $jeton = $user->createToken(
            'api-'.$user->role,
            ['*'],
            now()->addMinutes((int) config('securite.jeton_duree_minutes', 480))
        );

        Audit::enregistrer($action, $resume, $user, [], $user);

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'unite_id', 'militaire_id']),
            'token' => $jeton->plainTextToken,
            'expire_le' => $jeton->accessToken->expires_at,
        ], $statut);
    }

    /** Clés de limitation des ÉCHECS de connexion : par compte+IP, par compte, par IP. */
    private function limitesEchecs(string $email, string $ip): array
    {
        $c = config('securite.connexion');

        return [
            'login:email-ip:'.sha1($email.'|'.$ip) => (int) $c['echecs_email_ip'],
            'login:email:'.sha1($email) => (int) $c['echecs_email'],
            'login:ip:'.$ip => (int) $c['echecs_ip'],
        ];
    }

    private function verifierBlocage(string $email, string $ip): void
    {
        foreach ($this->limitesEchecs($email, $ip) as $cle => $max) {
            if (RateLimiter::tooManyAttempts($cle, $max)) {
                $secondes = RateLimiter::availableIn($cle);

                // Une seule trace par blocage (sinon une attaque remplirait le journal).
                if (Cache::add('login-bloque:'.$cle, 1, $secondes)) {
                    Audit::enregistrer('connexion_bloquee', 'Connexion bloquée après trop d\'échecs : '.$email, null, ['email' => $email]);
                }

                abort(429, 'Trop de tentatives de connexion. Réessayez dans '.max(1, (int) ceil($secondes / 60)).' minute(s).', ['Retry-After' => $secondes]);
            }
        }
    }

    private function enregistrerEchec(string $email, string $ip): void
    {
        $fenetre = (int) config('securite.connexion.fenetre_minutes', 15) * 60;

        foreach (array_keys($this->limitesEchecs($email, $ip)) as $cle) {
            RateLimiter::hit($cle, $fenetre);
        }
    }

    private function effacerEchecs(string $email, string $ip): void
    {
        RateLimiter::clear('login:email-ip:'.sha1($email.'|'.$ip));
        RateLimiter::clear('login:email:'.sha1($email));
    }
}
