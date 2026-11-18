<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\CategorieArticle;
use App\Models\Dotation;
use App\Models\DotationLigne;
use App\Models\Magasin;
use App\Models\Militaire;
use App\Models\Stock;
use App\Models\Unite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Politiques d'accès par objet (App\Policies) et nettoyage des données de test.
 * Lancer : php artisan test --filter=PoliciesTest
 */
class PoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function compte(string $role, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => "Compte {$role} {$n}",
            'email' => "{$role}-pol-{$n}@test.mg",
            'password' => Hash::make('phrase-de-passe-tres-longue-2026'),
            'role' => $role,
        ], $extra));
    }

    public function test_politique_militaire(): void
    {
        $a = Unite::factory()->create();
        $b = Unite::factory()->create();
        $chez_a = Militaire::factory()->create(['unite_id' => $a->id]);
        $chez_b = Militaire::factory()->create(['unite_id' => $b->id]);

        $admin = $this->compte(User::ROLE_ADMIN);
        $magasinier = $this->compte(User::ROLE_MAGASINIER);
        $chef = $this->compte(User::ROLE_CHEF_UNITE, ['unite_id' => $a->id]);
        $soldat = $this->compte(User::ROLE_MILITAIRE, ['militaire_id' => $chez_a->id]);

        foreach ([$admin, $magasinier] as $u) {
            $this->assertTrue(Gate::forUser($u)->allows('view', $chez_a));
            $this->assertTrue(Gate::forUser($u)->allows('view', $chez_b));
        }

        $this->assertTrue(Gate::forUser($chef)->allows('view', $chez_a));
        $this->assertTrue(Gate::forUser($chef)->denies('view', $chez_b));

        $this->assertTrue(Gate::forUser($soldat)->allows('view', $chez_a));
        $this->assertTrue(Gate::forUser($soldat)->denies('view', $chez_b));
        $this->assertTrue($soldat->can('view', $chez_a));
    }

    public function test_politique_unite(): void
    {
        $a = Unite::factory()->create();
        $b = Unite::factory()->create();
        $chef = $this->compte(User::ROLE_CHEF_UNITE, ['unite_id' => $a->id]);
        $admin = $this->compte(User::ROLE_ADMIN);

        $this->assertTrue(Gate::forUser($chef)->allows('view', $a));
        $this->assertTrue(Gate::forUser($chef)->denies('view', $b));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $b));
    }

    public function test_politique_magasin_rg8(): void
    {
        $unite = Unite::factory()->create();
        $sien = Magasin::factory()->create(['unite_id' => $unite->id]);
        $autre = Magasin::factory()->create(['unite_id' => $unite->id]);
        $magasinier = $this->compte(User::ROLE_MAGASINIER);
        $magasinier->magasins()->attach($sien->id);
        $admin = $this->compte(User::ROLE_ADMIN);

        $this->assertTrue(Gate::forUser($magasinier)->allows('operer', $sien));
        $this->assertTrue(Gate::forUser($magasinier)->denies('operer', $autre));
        $this->assertTrue(Gate::forUser($admin)->allows('operer', $autre));
    }

    public function test_acces_repond_403_avec_le_message_de_la_politique(): void
    {
        $a = Unite::factory()->create();
        $b = Unite::factory()->create();
        $etranger = Militaire::factory()->create(['unite_id' => $b->id]);
        $chef = $this->compte(User::ROLE_CHEF_UNITE, ['unite_id' => $a->id]);

        try {
            \App\Support\Acces::militaire($chef, $etranger);
            $this->fail('Un refus (403) était attendu.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('RG10', $e->getMessage());
        }
    }

    public function test_le_nettoyage_ne_touche_que_ce_qui_n_a_pas_d_historique(): void
    {
        $this->seed(ReferentielsSeeder::class);
        $unite = Unite::factory()->create();
        $magasin = Magasin::factory()->create(['unite_id' => $unite->id]);
        $admin = $this->compte(User::ROLE_ADMIN);
        $hab = CategorieArticle::where('code', 'HAB')->value('id');

        $propre = Article::create(['code' => 'T-1', 'designation' => 'Bonnet F2 Test', 'categorie_id' => $hab]);
        Stock::create(['magasin_id' => $magasin->id, 'article_id' => $propre->id, 'quantite' => 0]);

        $utilise = Article::create(['code' => 'T-2', 'designation' => 'Casque Test', 'categorie_id' => $hab]);
        $militaire = Militaire::factory()->create(['unite_id' => $unite->id, 'nom' => 'TEST']);
        $bon = Dotation::create([
            'numero_bon' => 'BD-T-1', 'militaire_id' => $militaire->id, 'magasin_id' => $magasin->id,
            'type' => Dotation::INITIALE, 'date' => now()->toDateString(), 'magasinier_id' => $admin->id,
        ]);
        DotationLigne::create(['dotation_id' => $bon->id, 'article_id' => $utilise->id, 'quantite' => 1]);

        $reel = Article::create(['code' => 'T-3', 'designation' => 'Testard bleu', 'categorie_id' => $hab]); // pas le MOT « test »

        // Simulation : rien ne bouge.
        $this->artisan('dotations:nettoyer-tests')->assertSuccessful();
        $this->assertDatabaseHas('articles', ['id' => $propre->id]);

        // Application : seul l'article sans historique disparaît (avec sa ligne de stock à zéro).
        $this->artisan('dotations:nettoyer-tests', ['--appliquer' => true])->expectsConfirmation('1 élément(s) vont être supprimés définitivement. Continuer ?', 'yes')->assertSuccessful();
        $this->assertDatabaseMissing('articles', ['id' => $propre->id]);
        $this->assertDatabaseHas('articles', ['id' => $utilise->id]);
        $this->assertDatabaseHas('articles', ['id' => $reel->id]);
        $this->assertDatabaseHas('militaires', ['id' => $militaire->id]); // « TEST », mais il a un bon
    }
}
