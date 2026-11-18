<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\CategoriePersonnel;
use App\Models\Dotation;
use App\Models\DotationLigne;
use App\Models\Grade;
use App\Models\Incident;
use App\Models\Magasin;
use App\Models\Mensuration;
use App\Models\Militaire;
use App\Models\Taille;
use App\Models\Unite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refonte, étape 1 : le modèle de données du cahier des charges et ses référentiels.
 * Lancer : php artisan test --filter=RefonteModeleTest
 */
class RefonteModeleTest extends TestCase
{
    use RefreshDatabase;

    private function taille(string $grille, string $valeur): Taille
    {
        return Taille::whereHas('grille', fn ($q) => $q->where('libelle', $grille))->where('valeur', $valeur)->firstOrFail();
    }

    public function test_les_referentiels_du_cahier_sont_en_place_et_relancables(): void
    {
        $this->seed(ReferentielsSeeder::class);
        $this->seed(ReferentielsSeeder::class); // sans doublon

        $this->assertDatabaseCount('categories_article', 6);
        $this->assertDatabaseCount('grilles_taille', 3);
        $this->assertDatabaseCount('categories_personnel', 4);
        $this->assertDatabaseCount('articles', 12);
        $this->assertSame(['38', '39', '40', '41', '42', '43', '44', '45', '46'], $this->taille('Pointure', '38')->grille->tailles->pluck('valeur')->all());

        $recrue = CategoriePersonnel::where('libelle', 'Recrue')->firstOrFail();
        $this->assertCount(12, $recrue->tableauDotation);
        $this->assertEqualsCanonicalizing(
            ['CAMP-SAC', 'CAMP-GOU', 'COU-COU'],
            Article::where('a_reintegrer', true)->pluck('code')->all()
        );
        $this->assertEquals(2, $recrue->tableauDotation()->whereHas('article', fn ($q) => $q->where('code', 'HAB-TRE'))->value('quantite_reglementaire'));
    }

    public function test_la_mensuration_la_plus_recente_preremplit_la_bonne_grille(): void
    {
        $this->seed(ReferentielsSeeder::class);
        $m = Militaire::factory()->create();
        Mensuration::create(['militaire_id' => $m->id, 'taille_vetement_id' => $this->taille('Vêtement', 'S')->id, 'date_mesure' => '2026-01-10']);
        Mensuration::create([
            'militaire_id' => $m->id, 'taille_vetement_id' => $this->taille('Vêtement', 'M')->id,
            'pointure_id' => $this->taille('Pointure', '41')->id, 'tour_tete_id' => $this->taille('Tour de tête', '56')->id, 'date_mesure' => '2026-03-01',
        ]);

        $actuelle = $m->mensurationActuelle;
        $beret = Article::where('code', 'COI-BER')->first();
        $ceinturon = Article::where('code', 'PEQ-CEI')->first();

        $this->assertSame('M', $actuelle->tailleVetement->valeur);
        $this->assertSame('56', $actuelle->taillePour($beret->grille_taille_id)?->valeur);
        $this->assertNull($actuelle->taillePour($ceinturon->grille_taille_id));
        $this->assertTrue($ceinturon->accepteTaille(null));
        $this->assertFalse(Article::where('code', 'HAB-TRE')->first()->accepteTaille($this->taille('Pointure', '40')));
    }

    public function test_l_historique_est_protege(): void
    {
        $this->seed(ReferentielsSeeder::class);
        $unite = Unite::factory()->create();
        $magasin = Magasin::factory()->create(['unite_id' => $unite->id]);
        $militaire = Militaire::factory()->create(['unite_id' => $unite->id]);
        $magasinier = User::factory()->create(['role' => User::ROLE_MAGASINIER]);
        $bon = Dotation::create([
            'numero_bon' => 'BD-1', 'militaire_id' => $militaire->id, 'magasin_id' => $magasin->id,
            'type' => Dotation::INITIALE, 'date' => '2026-03-02', 'magasinier_id' => $magasinier->id,
        ]);
        $ligne = DotationLigne::create(['dotation_id' => $bon->id, 'article_id' => Article::where('code', 'PEQ-CEI')->value('id'), 'quantite' => 1, 'date_echeance' => '2029-03-02']);
        $incident = Incident::create(['dotation_ligne_id' => $ligne->id, 'type' => Incident::PERTE, 'date' => '2026-05-01', 'pv_numero' => 'PV-1', 'imputable' => true]);
        Dotation::create([
            'numero_bon' => 'BD-2', 'militaire_id' => $militaire->id, 'magasin_id' => $magasin->id, 'type' => Dotation::REMPLACEMENT,
            'incident_id' => $incident->id, 'date' => '2026-05-02', 'magasinier_id' => $magasinier->id,
        ]);

        $this->assertSame('BD-2', $incident->remplacement->numero_bon);
        $this->assertSame(1, $militaire->paquetage()->count());

        foreach ([fn () => $ligne->delete(), fn () => $militaire->delete(), fn () => Grade::query()->whereKey($militaire->grade_id)->delete()] as $suppression) {
            try {
                $suppression();
                $this->fail('Suppression acceptée alors qu\'un historique en dépend.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
