<?php

namespace Tests\Feature;

use App\Models\Militaire;
use App\Models\Unite;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests d'intégration de sécurité : contrôle d'accès par objet, authentification, limitation de débit, 2FA.
 * Lancer : php artisan test --filter=SecuriteTest
 */
class SecuriteTest extends TestCase
{
    use RefreshDatabase;

    private const MDP = 'phrase-de-passe-tres-longue-2026';

    private function compte(string $role, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => "Compte {$role} {$n}",
            'email' => "{$role}{$n}@test.mg",
            'password' => Hash::make(self::MDP),
            'role' => $role,
        ], $extra));
    }

    private function unite(): Unite
    {
        return Unite::factory()->create();
    }

    private function militaire(Unite $unite): Militaire
    {
        return Militaire::factory()->create(['unite_id' => $unite->id]);
    }

    // ------------------------------------------------------------------ en-têtes et jetons

    public function test_les_reponses_portent_les_en_tetes_de_securite(): void
    {
        $r = $this->postJson('/api/login', ['email' => 'x@test.mg', 'password' => 'x']);

        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_un_jeton_inutilise_trop_longtemps_est_refuse(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);
        $jeton = $user->createToken('t', ['*'], now()->addHours(8));
        $jeton->accessToken->forceFill(['last_used_at' => now()->subMinutes(31)])->save();

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$jeton->plainTextToken])->assertUnauthorized();
    }

    public function test_un_jeton_expire_est_refuse(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);
        $jeton = $user->createToken('t', ['*'], now()->subMinute());

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$jeton->plainTextToken])->assertUnauthorized();
    }

    // ------------------------------------------------------------------ connexion

    public function test_connexion_reussie_delivre_un_jeton_qui_expire(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);

        $r = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::MDP])->assertOk();

        $this->assertNotEmpty($r->json('token'));
        $this->assertNotNull($user->tokens()->first()->expires_at);
    }

    public function test_apres_cinq_echecs_le_compte_est_bloque_temporairement(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'mauvais'])->assertStatus(422);
        }

        // Même avec le bon mot de passe : bloqué, et Retry-After indiqué.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::MDP])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_email_inconnu_et_mauvais_mot_de_passe_donnent_la_meme_reponse(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);

        $a = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'mauvais'])->assertStatus(422);
        $b = $this->postJson('/api/login', ['email' => 'inconnu@test.mg', 'password' => 'mauvais'])->assertStatus(422);

        $this->assertSame($a->json('errors'), $b->json('errors'));
    }

    // ------------------------------------------------------------------ accès par objet (BOLA / IDOR)

    // Les contrôles d'accès sur les mensurations, les fiches, les stocks et les bons sont réécrits avec la refonte
    // (cahier des charges officiel) : tests/Feature/Refonte*Test.php, au fur et à mesure des étapes.

    // ------------------------------------------------------------------ comptes

    public function test_un_mot_de_passe_faible_est_refuse_a_la_creation_de_compte(): void
    {
        Sanctum::actingAs($this->compte(User::ROLE_ADMIN));

        $this->postJson('/api/users', ['name' => 'Test', 'email' => 'nouveau@test.mg', 'password' => 'password123', 'role' => 'magasinier'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/users', ['name' => 'Test', 'email' => 'nouveau@test.mg', 'password' => 'vert-lumineux-fusée-sablier-47', 'role' => 'magasinier'])
            ->assertCreated();
    }

    public function test_le_dernier_administrateur_ne_peut_ni_se_supprimer_ni_se_retrograder(): void
    {
        $admin = $this->compte(User::ROLE_ADMIN);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'magasinier'])->assertStatus(422);
    }

    public function test_changer_de_role_ferme_les_sessions_du_compte(): void
    {
        $admin = $this->compte(User::ROLE_ADMIN);
        $cible = $this->compte(User::ROLE_MAGASINIER);
        $cible->createToken('t');
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$cible->id}", ['role' => 'militaire'])->assertOk();
        $this->assertSame(0, $cible->tokens()->count());
    }

    public function test_l_enrolement_donne_la_meme_reponse_pour_tous_les_refus(): void
    {
        $militaire = $this->militaire($this->unite());
        $corps = fn (array $surcharge = []) => array_merge([
            'matricule' => $militaire->matricule,
            'date_incorporation' => $militaire->date_incorporation->format('Y-m-d'),
            'email' => 'nouveau@test.mg',
            'password' => 'vert-lumineux-fusée-sablier-47',
            'password_confirmation' => 'vert-lumineux-fusée-sablier-47',
        ], $surcharge);

        $mauvaiseDate = $this->postJson('/api/register', $corps(['date_incorporation' => '1999-01-01']))->assertStatus(422);
        $inconnu = $this->postJson('/api/register', $corps(['matricule' => 'INCONNU-1']))->assertStatus(422);

        $this->assertSame($mauvaiseDate->json('errors'), $inconnu->json('errors'));
        $this->postJson('/api/register', $corps())->assertCreated();
    }

    // ------------------------------------------------------------------ mot de passe oublié

    public function test_le_courriel_de_reinitialisation_mene_au_frontend_avec_le_jeton_dans_le_fragment(): void
    {
        Notification::fake();
        $user = $this->compte(User::ROLE_MAGASINIER);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $lien = $notification->toMail($user)->actionUrl;

            return str_starts_with($lien, rtrim((string) config('securite.frontend_url'), '/').'/#reinitialiser?token=')
                && str_contains($lien, '&email='.urlencode($user->email));
        });
    }

    public function test_la_demande_de_lien_donne_la_meme_reponse_que_le_compte_existe_ou_non(): void
    {
        Notification::fake();
        $user = $this->compte(User::ROLE_MAGASINIER);

        $connu = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $inconnu = $this->postJson('/api/forgot-password', ['email' => 'personne@test.mg']);

        $this->assertSame($connu->status(), $inconnu->status());
        $this->assertSame($connu->json(), $inconnu->json());
    }

    public function test_la_reinitialisation_change_le_mot_de_passe_ferme_les_sessions_et_le_jeton_ne_sert_qu_une_fois(): void
    {
        $user = $this->compte(User::ROLE_MAGASINIER);
        $user->createToken('ancien-appareil');
        $corps = [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'cerise-lumineuse-horizon-48-valise',
            'password_confirmation' => 'cerise-lumineuse-horizon-48-valise',
        ];

        $this->postJson('/api/reset-password', $corps)->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('cerise-lumineuse-horizon-48-valise', $user->fresh()->password));
        $this->postJson('/api/reset-password', $corps)->assertStatus(422);
    }

    public function test_une_reinitialisation_refusee_donne_le_meme_message_pour_un_jeton_faux_et_un_email_inconnu(): void
    {
        $user = $this->compte(User::ROLE_MAGASINIER);
        $corps = fn (string $email) => [
            'token' => 'jeton-invalide',
            'email' => $email,
            'password' => 'cerise-lumineuse-horizon-48-valise',
            'password_confirmation' => 'cerise-lumineuse-horizon-48-valise',
        ];

        $connu = $this->postJson('/api/reset-password', $corps($user->email))->assertStatus(422);
        $inconnu = $this->postJson('/api/reset-password', $corps('inconnu@test.mg'))->assertStatus(422);

        $this->assertSame($connu->json('errors'), $inconnu->json('errors'));
    }

    // ------------------------------------------------------------------ double authentification

    public function test_parcours_complet_de_la_double_authentification(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);
        Sanctum::actingAs($user);

        // Activation : préparer (mot de passe exigé), puis confirmer avec un code valide.
        $this->postJson('/api/2fa/preparer', ['password' => 'faux'])->assertStatus(422);
        $secret = $this->postJson('/api/2fa/preparer', ['password' => self::MDP])->assertOk()->json('secret');
        $codes = $this->postJson('/api/2fa/confirmer', ['code' => Totp::code($secret, Totp::pasCourant())])->assertOk()->json('codes_secours');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->aDoubleFacteur());

        // Connexion : le mot de passe seul ne donne plus de jeton.
        $etape1 = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::MDP])->assertOk();
        $this->assertTrue($etape1->json('two_factor'));
        $this->assertNull($etape1->json('token'));
        $defi = $etape1->json('defi');

        // Mauvais code refusé, bon code accepté (un code de secours ne sert qu'une fois).
        $this->postJson('/api/login/2fa', ['defi' => $defi, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/login/2fa', ['defi' => $defi, 'code_secours' => $codes[0]])->assertOk()->assertJsonStructure(['token']);

        $defi2 = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::MDP])->json('defi');
        $this->postJson('/api/login/2fa', ['defi' => $defi2, 'code_secours' => $codes[0]])->assertStatus(422);
    }

    public function test_les_secrets_2fa_ne_sortent_jamais_de_l_api(): void
    {
        $user = $this->compte(User::ROLE_ADMIN);
        $user->forceFill(['two_factor_secret' => Totp::genererSecret(), 'two_factor_confirmed_at' => now()])->save();
        Sanctum::actingAs($user);

        $json = json_encode($this->getJson('/api/me')->json());
        $this->assertStringNotContainsString('two_factor_secret', $json);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $json);
    }
}
