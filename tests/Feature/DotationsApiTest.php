<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Dotation;
use App\Models\DotationLigne;
use App\Models\Grade;
use App\Models\GrilleTaille;
use App\Models\Magasin;
use App\Models\Mensuration;
use App\Models\Militaire;
use App\Models\Stock;
use App\Models\Unite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Refonte, étapes B3 à B6 : stock, bons de dotation, incidents, réintégration, indicateurs (contrat F3 à F6).
 * Lancer : php artisan test --filter=DotationsApiTest
 */
class DotationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Unite $cir;

    private Unite $blog;

    private Magasin $mag;

    private Magasin $autreMag;

    private User $magasinier;

    private Militaire $recrue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielsSeeder::class);
        $this->cir = Unite::factory()->create(['code' => 'CIR']);
        $this->blog = Unite::factory()->create(['code' => 'BLOG']);
        $this->mag = Magasin::factory()->create(['unite_id' => $this->cir->id]);
        $this->autreMag = Magasin::factory()->create(['unite_id' => $this->blog->id]);
        $this->magasinier = User::factory()->create(['role' => User::ROLE_MAGASINIER]);
        $this->magasinier->magasins()->attach($this->mag->id);

        // Stock abondant partout dans le magasin du CIR
        foreach (Article::with('grilleTaille.tailles')->get() as $a) {
            foreach ($a->grilleTaille?->tailles ?? [null] as $t) {
                Stock::create(['magasin_id' => $this->mag->id, 'article_id' => $a->id, 'taille_id' => $t?->id, 'quantite' => 50, 'seuil_alerte' => 5]);
            }
        }

        $this->recrue = Militaire::factory()->create(['unite_id' => $this->cir->id, 'grade_id' => Grade::where('libelle', 'Recrue')->value('id')]);
        $taille = fn ($g, $v) => GrilleTaille::where('libelle', $g)->first()->tailles()->where('valeur', $v)->value('id');
        Mensuration::create(['militaire_id' => $this->recrue->id, 'date_mesure' => now()->toDateString(),
            'taille_vetement_id' => $taille('Vêtement', 'M'), 'pointure_id' => $taille('Pointure', '41'), 'tour_tete_id' => $taille('Tour de tête', '56')]);
    }

    private function en(User|string $qui, array $extra = []): User
    {
        $u = $qui instanceof User ? $qui : User::factory()->create(['role' => $qui] + $extra);
        Sanctum::actingAs($u);

        return $u;
    }

    private function doterInitialement(): array
    {
        $p = $this->getJson("/api/dotations/proposition?militaire_id={$this->recrue->id}&magasin_id={$this->mag->id}&type=initiale")->assertOk();
        $lignes = collect($p->json('lignes'))->map(fn ($l) => ['article_id' => $l['article']['id'], 'taille_id' => $l['taille']['id'] ?? null, 'quantite' => $l['quantite']])->all();

        return $this->postJson('/api/dotations', ['militaire_id' => $this->recrue->id, 'magasin_id' => $this->mag->id, 'type' => 'initiale', 'lignes' => $lignes])
            ->assertCreated()->json();
    }

    public function test_stock_entree_reforme_seuils_et_journal(): void
    {
        $this->en($this->magasinier);
        $cei = Article::where('code', 'PEQ-CEI')->first();

        $this->getJson("/api/magasins/{$this->mag->id}/stock")->assertOk()->assertJsonCount(46);
        $this->getJson("/api/magasins/{$this->autreMag->id}/stock")->assertForbidden();

        $this->postJson("/api/magasins/{$this->mag->id}/entrees", ['reference' => 'bl-2026-118', 'lignes' => [['article_id' => $cei->id, 'taille_id' => null, 'quantite' => 10]]])
            ->assertCreated()->assertJsonPath('reference', 'BL-2026-118');
        $this->postJson("/api/magasins/{$this->mag->id}/reformes", ['reference' => 'PVR-1', 'motif' => 'Usure', 'lignes' => [['article_id' => $cei->id, 'taille_id' => null, 'quantite' => 999]]])
            ->assertStatus(422)->assertJsonPath('message', 'Stock insuffisant : 60 « Ceinturon » en stock, 999 à réformer.');
        $this->postJson("/api/magasins/{$this->mag->id}/reformes", ['reference' => 'PVR-1', 'lignes' => [['article_id' => $cei->id, 'taille_id' => null, 'quantite' => 1]]])
            ->assertStatus(422); // motif obligatoire
        $this->postJson("/api/magasins/{$this->mag->id}/reformes", ['reference' => 'PVR-1', 'motif' => 'Usure', 'lignes' => [['article_id' => $cei->id, 'taille_id' => null, 'quantite' => 2]]])
            ->assertCreated();
        $this->patchJson("/api/magasins/{$this->mag->id}/seuils", ['lignes' => [['article_id' => $cei->id, 'taille_id' => null, 'seuil_alerte' => 100]]])
            ->assertOk()->assertJsonPath('modifies', 1);

        $this->getJson("/api/mouvements-stock?magasin_id={$this->mag->id}")->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.type', 'reforme')->assertJsonPath('data.0.motif', 'Usure');
        $this->getJson("/api/mouvements-stock?magasin_id={$this->autreMag->id}")->assertForbidden();
    }

    public function test_dotation_initiale_puis_regles(): void
    {
        $this->en($this->magasinier);
        $bon = $this->doterInitialement();
        $this->assertSame('BD-'.now()->year.'-000001', $bon['numero_bon']);
        $this->assertSame(12, $bon['nb_lignes']);
        $this->assertSame(18, $bon['nb_pieces']);

        // seconde dotation initiale
        $p = $this->getJson("/api/dotations/proposition?militaire_id={$this->recrue->id}&magasin_id={$this->mag->id}&type=initiale")->assertOk()->assertJsonPath('deja_dote', true);
        $this->assertStringStartsWith('Déjà en service', $p->json('lignes.0.probleme'));
        $cei = Article::where('code', 'PEQ-CEI')->value('id');
        $this->postJson('/api/dotations', ['militaire_id' => $this->recrue->id, 'magasin_id' => $this->mag->id, 'type' => 'initiale', 'lignes' => [['article_id' => $cei, 'quantite' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'a déjà reçu sa dotation initiale'));

        // magasin non rattaché
        $this->postJson('/api/dotations', ['militaire_id' => $this->recrue->id, 'magasin_id' => $this->autreMag->id, 'type' => 'renouvellement', 'lignes' => [['article_id' => $cei, 'quantite' => 1]]])
            ->assertForbidden();

        // renouvellement d'un article échu
        $tsk = DotationLigne::where('article_id', Article::where('code', 'HAB-TSK')->value('id'))->first();
        $tsk->update(['date_echeance' => now()->subDay()->toDateString()]);
        $p = $this->getJson("/api/dotations/proposition?militaire_id={$this->recrue->id}&magasin_id={$this->mag->id}&type=renouvellement")->assertOk()->assertJsonCount(1, 'lignes');
        $this->postJson('/api/dotations', ['militaire_id' => $this->recrue->id, 'magasin_id' => $this->mag->id, 'type' => 'renouvellement', 'lignes' => [[
            'article_id' => $tsk->article_id, 'taille_id' => $tsk->taille_id, 'quantite' => 3, 'remplace_ligne_id' => $p->json('lignes.0.remplace_ligne_id'),
        ]]])->assertCreated()->assertJsonPath('type', 'renouvellement');
        $this->assertSame('reforme', $tsk->fresh()->etat);

        $this->getJson("/api/dotations/{$bon['id']}")->assertOk()->assertJsonCount(12, 'lignes');
        $this->getJson('/api/dotations')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_consultation_des_bons_selon_le_role(): void
    {
        $this->en($this->magasinier);
        $bon = $this->doterInitialement();

        $this->en(User::ROLE_CHEF_UNITE, ['unite_id' => $this->blog->id]);
        $this->getJson('/api/dotations')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/dotations/{$bon['id']}")->assertForbidden();

        $this->en(User::ROLE_CHEF_UNITE, ['unite_id' => $this->cir->id]);
        $this->getJson("/api/dotations/{$bon['id']}")->assertOk();
        $this->postJson('/api/dotations', [])->assertForbidden();

        $this->en(User::ROLE_MILITAIRE, ['militaire_id' => $this->recrue->id]);
        $this->getJson('/api/dotations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/militaires/{$this->recrue->id}/paquetage")->assertOk()->assertJsonCount(12);
        $this->getJson('/api/mon-espace')->assertOk()->assertJsonPath('resume.articles', 12)->assertJsonPath('resume.a_reintegrer', 3);
    }

    public function test_incident_remplacement_reintegration_et_cloture(): void
    {
        $this->en($this->magasinier);
        $this->doterInitialement();
        $cht = DotationLigne::where('article_id', Article::where('code', 'HAB-CHT')->value('id'))->first();

        $incident = $this->postJson("/api/dotation-lignes/{$cht->id}/incidents", ['type' => 'deterioration', 'pv_numero' => 'pv-2026-050', 'imputable' => false, 'quantite' => 1])
            ->assertCreated()->assertJsonPath('pv_numero', 'PV-2026-050')->assertJsonPath('ligne.quantite', 1)->assertJsonPath('ligne.etat', 'reforme')->json();
        $this->assertSame(3, $cht->fresh()->quantite);
        $this->postJson("/api/dotation-lignes/{$cht->id}/incidents", ['type' => 'perte', 'pv_numero' => 'PV-2026-050', 'imputable' => true])
            ->assertStatus(422)->assertJsonPath('errors.pv_numero.0', 'Le PV PV-2026-050 est déjà enregistré.');

        $this->getJson('/api/incidents?a_remplacer=1')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/incidents/{$incident['id']}/remplacement", ['magasin_id' => $this->mag->id])->assertCreated()->assertJsonPath('type', 'remplacement');
        $this->postJson("/api/incidents/{$incident['id']}/remplacement", ['magasin_id' => $this->mag->id])->assertStatus(422);
        $this->getJson('/api/incidents?a_remplacer=1')->assertJsonPath('total', 0);

        $admin = $this->en(User::ROLE_ADMIN);
        $this->patchJson("/api/militaires/{$this->recrue->id}/statut", ['statut' => 'radie', 'motif' => 'Fin de contrat'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Clôture impossible : 3 article(s)'));

        $aRendre = DotationLigne::whereHas('article', fn ($a) => $a->where('a_reintegrer', true))->pluck('id')->all();
        $this->postJson("/api/militaires/{$this->recrue->id}/reintegration", ['magasin_id' => $this->mag->id, 'lignes' => $aRendre])
            ->assertCreated()->assertJsonPath('reference', 'RI-'.now()->year.'-000001')->assertJsonCount(3, 'lignes');
        $this->patchJson("/api/militaires/{$this->recrue->id}/statut", ['statut' => 'radie', 'motif' => 'Fin de contrat'])->assertOk();
    }

    public function test_indicateurs(): void
    {
        $this->en($this->magasinier);
        $this->doterInitialement();
        DotationLigne::where('article_id', Article::where('code', 'HAB-TSK')->value('id'))->update(['date_echeance' => now()->subDay()->toDateString()]);
        DotationLigne::where('article_id', Article::where('code', 'HAB-CHT')->value('id'))->update(['date_echeance' => now()->addDays(10)->toDateString()]);

        $this->getJson('/api/tableau-de-bord')->assertOk()
            ->assertJsonPath('echeances.echues', 1)->assertJsonPath('echeances.a_30_jours', 1)
            ->assertJsonCount(8, 'sorties_par_semaine')->assertJsonStructure(['stock' => ['alertes', 'ruptures', 'lignes']]);
        $this->getJson('/api/previsions')->assertForbidden(); // magasinier

        $this->en(User::ROLE_CHEF_UNITE, ['unite_id' => $this->cir->id]);
        $this->getJson('/api/tableau-de-bord')->assertOk()->assertJsonMissingPath('stock')->assertJsonPath('echeances.echues', 1);
        $prev = $this->getJson('/api/previsions')->assertOk()->assertJsonCount(13, 'par_mois');
        $tsk = collect($prev->json('lignes'))->firstWhere('article.code', 'HAB-TSK');
        $this->assertSame(3, $tsk['en_retard']);

        $this->en(User::ROLE_CHEF_UNITE, ['unite_id' => $this->blog->id]);
        $this->getJson('/api/tableau-de-bord')->assertOk()->assertJsonPath('echeances.echues', 0);

        $this->en(User::ROLE_MILITAIRE);
        $this->getJson('/api/mon-espace')->assertNotFound();
        $this->getJson('/api/tableau-de-bord')->assertForbidden();
    }
}
