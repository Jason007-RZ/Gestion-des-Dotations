<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\CategorieArticle;
use App\Models\CategoriePersonnel;
use App\Models\Grade;
use App\Models\GrilleTaille;
use App\Models\Magasin;
use App\Models\Militaire;
use App\Models\Stock;
use App\Models\TableauDotation;
use App\Models\Unite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Refonte, étape B1 : API des référentiels (contrat API-REFONTE.md, F1).
 * Lancer : php artisan test --filter=ReferentielsApiTest
 */
class ReferentielsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielsSeeder::class);
    }

    private function en(string $role, array $extra = []): User
    {
        $u = User::factory()->create(['role' => $role] + $extra);
        Sanctum::actingAs($u);

        return $u;
    }

    private function article(string $code): Article
    {
        return Article::where('code', $code)->firstOrFail();
    }

    public function test_les_referentiels_sont_lisibles_par_tous_les_roles(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_MAGASINIER, User::ROLE_CHEF_UNITE, User::ROLE_MILITAIRE] as $role) {
            $this->en($role);
            $r = $this->getJson('/api/referentiels')->assertOk();
            $this->assertCount(6, $r->json('categories_article'));
            $this->assertCount(3, $r->json('grilles_taille'));
            $this->assertSame('S', $r->json('grilles_taille.0.tailles.0.valeur'));
            $this->assertSame(12, $r->json('categories_personnel.0.nb_lignes_tableau'));
            $this->assertNotNull($r->json('grilles_mensuration.pointure'));
            $this->getJson('/api/articles')->assertOk()->assertJsonCount(12)->assertJsonPath('0.categorie.code', 'CAMP');
        }
    }

    public function test_seul_l_administrateur_modifie_le_catalogue(): void
    {
        $this->en(User::ROLE_MAGASINIER);
        $this->postJson('/api/articles', ['code' => 'X', 'designation' => 'X', 'categorie_id' => 1])->assertForbidden();
        $this->getJson('/api/tableau-dotation')->assertOk();

        $this->en(User::ROLE_MILITAIRE);
        $this->getJson('/api/tableau-dotation')->assertForbidden();
    }

    public function test_creer_modifier_desactiver_un_article(): void
    {
        $this->en(User::ROLE_ADMIN);
        $hab = CategorieArticle::where('code', 'HAB')->value('id');
        $vetement = GrilleTaille::where('libelle', 'Vêtement')->value('id');

        $r = $this->postJson('/api/articles', [
            'code' => 'hab-plu', 'designation' => 'Veste de pluie', 'categorie_id' => $hab,
            'grille_taille_id' => $vetement, 'duree_vie_mois' => 24, 'a_reintegrer' => false, 'actif' => true,
        ])->assertCreated()->assertJsonPath('code', 'HAB-PLU')->assertJsonPath('grille_taille.libelle', 'Vêtement');

        $this->postJson('/api/articles', ['code' => 'HAB-PLU', 'designation' => 'x', 'categorie_id' => $hab])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Le code HAB-PLU est déjà utilisé.');

        $id = $r->json('id');
        $this->putJson("/api/articles/{$id}", [
            'code' => 'HAB-PLU', 'designation' => 'Veste de pluie', 'categorie_id' => $hab,
            'grille_taille_id' => $vetement, 'duree_vie_mois' => null, 'a_reintegrer' => true, 'actif' => false,
        ])->assertOk()->assertJsonPath('actif', false)->assertJsonPath('duree_vie_mois', null);

        $this->deleteJson("/api/articles/{$id}")->assertNoContent();
    }

    public function test_un_article_utilise_ne_se_supprime_pas_et_ne_change_pas_de_grille(): void
    {
        $this->en(User::ROLE_ADMIN);
        $tre = $this->article('HAB-TRE');
        $this->deleteJson("/api/articles/{$tre->id}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tableau de dotation'));

        $magasin = Magasin::factory()->create();
        Stock::create(['magasin_id' => $magasin->id, 'article_id' => $tre->id, 'taille_id' => $tre->grilleTaille->tailles->first()->id, 'quantite' => 3]);
        $this->putJson("/api/articles/{$tre->id}", [
            'code' => $tre->code, 'designation' => $tre->designation, 'categorie_id' => $tre->categorie_id,
            'grille_taille_id' => GrilleTaille::where('libelle', 'Pointure')->value('id'), 'duree_vie_mois' => 12,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'grille de taille'));
    }

    public function test_tableau_de_dotation(): void
    {
        $this->en(User::ROLE_ADMIN);
        $recrue = CategoriePersonnel::where('libelle', 'Recrue')->first();
        $officier = CategoriePersonnel::where('libelle', 'Officier')->first();
        $cei = $this->article('PEQ-CEI');

        $this->getJson("/api/tableau-dotation?categorie_personnel_id={$recrue->id}")->assertOk()->assertJsonCount(12);

        // doublon refusé
        $this->postJson('/api/tableau-dotation', ['categorie_personnel_id' => $recrue->id, 'article_id' => $cei->id, 'quantite_reglementaire' => 1, 'periodicite_mois' => 36])
            ->assertStatus(422)->assertJsonPath('message', '« Ceinturon » figure déjà dans le tableau de cette catégorie.');

        // copie Recrue -> Officier, puis seconde copie : tout est ignoré
        $this->postJson('/api/tableau-dotation/copier', ['source_id' => $recrue->id, 'cible_id' => $officier->id])->assertOk()->assertExactJson(['copiees' => 12, 'ignorees' => 0]);
        $this->postJson('/api/tableau-dotation/copier', ['source_id' => $recrue->id, 'cible_id' => $officier->id])->assertOk()->assertExactJson(['copiees' => 0, 'ignorees' => 12]);

        // modification : réintégrable (périodicité vide)
        $ligne = TableauDotation::where('categorie_personnel_id', $officier->id)->where('article_id', $cei->id)->first();
        $this->putJson("/api/tableau-dotation/{$ligne->id}", ['quantite_reglementaire' => 2, 'periodicite_mois' => null])
            ->assertOk()->assertJsonPath('quantite_reglementaire', 2)->assertJsonPath('periodicite_mois', null);
        $this->putJson("/api/tableau-dotation/{$ligne->id}", ['quantite_reglementaire' => 0, 'periodicite_mois' => 12])->assertStatus(422);

        // article désactivé : refusé
        $ligne->delete();
        $cei->update(['actif' => false]);
        $this->postJson('/api/tableau-dotation', ['categorie_personnel_id' => $officier->id, 'article_id' => $cei->id, 'quantite_reglementaire' => 1, 'periodicite_mois' => 36])
            ->assertStatus(422)->assertJsonPath('message', 'Cet article est désactivé : réactivez-le dans le catalogue.');
    }

    public function test_grilles_et_tailles(): void
    {
        $this->en(User::ROLE_ADMIN);
        $pointure = GrilleTaille::where('libelle', 'Pointure')->first();
        $this->postJson("/api/grilles-taille/{$pointure->id}/tailles", ['valeur' => '47'])->assertCreated()->assertJsonPath('ordre', 10);
        $this->postJson("/api/grilles-taille/{$pointure->id}/tailles", ['valeur' => '47'])->assertStatus(422);
        $t47 = $pointure->tailles()->where('valeur', '47')->first();
        $this->putJson("/api/tailles/{$t47->id}", ['ordre' => 0])->assertOk()->assertJsonPath('ordre', 0);
        $this->deleteJson("/api/tailles/{$t47->id}")->assertNoContent();
        $this->deleteJson("/api/grilles-taille/{$pointure->id}")->assertStatus(422);
        $g = $this->postJson('/api/grilles-taille', ['libelle' => 'Tour de taille'])->assertCreated()->json('id');
        $this->deleteJson("/api/grilles-taille/{$g}")->assertNoContent();
    }

    public function test_grades_unites_magasins_et_suppressions_protegees(): void
    {
        $this->en(User::ROLE_ADMIN);
        $officier = CategoriePersonnel::where('libelle', 'Officier')->first();
        $this->postJson('/api/grades', ['libelle' => 'Général de brigade', 'categorie_personnel_id' => $officier->id])->assertCreated();
        $this->postJson('/api/grades', ['libelle' => 'Général de brigade', 'categorie_personnel_id' => $officier->id])->assertStatus(422);
        $this->deleteJson("/api/categories-personnel/{$officier->id}")->assertStatus(422);

        $unite = $this->postJson('/api/unites', ['code' => '2rg', 'libelle' => '2e Régiment du Génie'])->assertCreated()->assertJsonPath('code', '2RG')->json('id');
        $magasin = $this->postJson('/api/magasins', ['libelle' => 'Magasin du 2RG', 'unite_id' => $unite])->assertCreated()->assertJsonPath('unite.code', '2RG')->json('id');
        $this->deleteJson("/api/unites/{$unite}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'magasin'));
        $this->deleteJson("/api/magasins/{$magasin}")->assertNoContent();
        $this->deleteJson("/api/unites/{$unite}")->assertNoContent();

        $grade = Grade::where('libelle', 'Recrue')->first();
        Militaire::factory()->create(['grade_id' => $grade->id]);
        $this->deleteJson("/api/grades/{$grade->id}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'portent le grade'));

        $hab = CategorieArticle::where('code', 'HAB')->first();
        $this->deleteJson("/api/categories-article/{$hab->id}")->assertStatus(422);
        $this->postJson('/api/categories-article', ['code' => 'hyg', 'libelle' => 'Hygiène'])->assertCreated()->assertJsonPath('code', 'HYG');
    }
}
