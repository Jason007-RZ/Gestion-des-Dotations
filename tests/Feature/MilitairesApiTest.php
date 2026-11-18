<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Dotation;
use App\Models\DotationLigne;
use App\Models\Grade;
use App\Models\GrilleTaille;
use App\Models\Magasin;
use App\Models\Militaire;
use App\Models\Unite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Refonte, étape B2 : militaires et mensurations (contrat API-REFONTE.md, F2).
 * Lancer : php artisan test --filter=MilitairesApiTest
 */
class MilitairesApiTest extends TestCase
{
    use RefreshDatabase;

    private Unite $cir;

    private Unite $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielsSeeder::class);
        $this->cir = Unite::factory()->create(['code' => 'CIR']);
        $this->blog = Unite::factory()->create(['code' => 'BLOG']);
    }

    private function en(string $role, array $extra = []): User
    {
        $u = User::factory()->create(['role' => $role] + $extra);
        Sanctum::actingAs($u);

        return $u;
    }

    private function taille(string $grille, string $valeur): int
    {
        return GrilleTaille::where('libelle', $grille)->firstOrFail()->tailles()->where('valeur', $valeur)->value('id');
    }

    private function recrue(Unite $u, array $extra = []): Militaire
    {
        return Militaire::factory()->create(['unite_id' => $u->id, 'grade_id' => Grade::where('libelle', 'Recrue')->value('id')] + $extra);
    }

    public function test_creer_un_militaire_puis_le_retrouver(): void
    {
        $this->en(User::ROLE_ADMIN);
        $r = $this->postJson('/api/militaires', [
            'matricule' => 'mat-99001', 'nom' => 'Rasoanaivo', 'prenoms' => 'Mialy',
            'grade_id' => Grade::where('libelle', 'Recrue')->value('id'), 'unite_id' => $this->cir->id, 'date_incorporation' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('matricule', 'MAT-99001')->assertJsonPath('nom', 'RASOANAIVO')->assertJsonPath('statut', 'actif')
            ->assertJsonPath('grade.categorie.libelle', 'Recrue')->assertJsonPath('unite.code', 'CIR')
            ->assertJsonPath('date_incorporation', now()->toDateString());

        $this->postJson('/api/militaires', ['matricule' => 'MAT-99001', 'nom' => 'x', 'prenoms' => 'y', 'grade_id' => 1, 'unite_id' => $this->cir->id, 'date_incorporation' => '2026-01-01'])
            ->assertStatus(422)->assertJsonPath('errors.matricule.0', 'Le matricule MAT-99001 est déjà utilisé.');
        $this->postJson('/api/militaires', ['matricule' => 'MAT-99002', 'nom' => 'x', 'prenoms' => 'y', 'grade_id' => 1, 'unite_id' => $this->cir->id, 'date_incorporation' => now()->addDay()->toDateString()])
            ->assertStatus(422);

        $this->getJson('/api/militaires?q=99001')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $r->json('id'));
    }

    public function test_liste_filtree_et_paginee(): void
    {
        $this->en(User::ROLE_MAGASINIER);
        for ($i = 0; $i < 25; $i++) {
            $this->recrue($this->cir);
        }
        $this->recrue($this->blog, ['statut' => 'mute']);

        $this->getJson('/api/militaires')->assertOk()->assertJsonPath('total', 26)->assertJsonPath('per_page', 20)->assertJsonCount(20, 'data');
        $this->getJson('/api/militaires?statut=actif')->assertJsonPath('total', 25);
        $this->getJson("/api/militaires?unite_id={$this->blog->id}")->assertJsonPath('total', 1);
        $this->getJson('/api/militaires?sans_mensuration=1')->assertJsonPath('total', 26);
    }

    public function test_le_chef_d_unite_ne_voit_que_son_unite(): void
    {
        $chez_moi = $this->recrue($this->cir);
        $ailleurs = $this->recrue($this->blog);
        $this->en(User::ROLE_CHEF_UNITE, ['unite_id' => $this->cir->id]);

        $this->getJson("/api/militaires?unite_id={$this->blog->id}")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $chez_moi->id);
        $this->getJson("/api/militaires/{$chez_moi->id}")->assertOk();
        $this->getJson("/api/militaires/{$ailleurs->id}")->assertForbidden();
        $this->postJson("/api/militaires/{$chez_moi->id}/mensurations", ['taille_vetement_id' => null, 'pointure_id' => null, 'tour_tete_id' => null])->assertForbidden();
    }

    public function test_le_militaire_n_a_pas_acces_a_la_liste(): void
    {
        $this->en(User::ROLE_MILITAIRE);
        $this->getJson('/api/militaires')->assertForbidden();
    }

    public function test_mensurations_et_historique(): void
    {
        $m = $this->recrue($this->cir);
        $magasinier = $this->en(User::ROLE_MAGASINIER);

        $this->postJson("/api/militaires/{$m->id}/mensurations", ['taille_vetement_id' => null, 'pointure_id' => null, 'tour_tete_id' => null])
            ->assertStatus(422)->assertJsonPath('errors.taille_vetement_id.0', 'Indiquez au moins une mesure.');
        $this->postJson("/api/militaires/{$m->id}/mensurations", ['taille_vetement_id' => $this->taille('Pointure', '41'), 'pointure_id' => null, 'tour_tete_id' => null])
            ->assertStatus(422)->assertJsonPath('errors.taille_vetement_id.0', "La taille 41 n'appartient pas à la grille Vêtement.");

        $this->postJson("/api/militaires/{$m->id}/mensurations", [
            'date_mesure' => now()->subDays(10)->toDateString(),
            'taille_vetement_id' => $this->taille('Vêtement', 'M'), 'pointure_id' => $this->taille('Pointure', '41'), 'tour_tete_id' => null,
        ])->assertCreated()->assertJsonPath('taille_vetement.valeur', 'M')->assertJsonPath('saisie_par.id', $magasinier->id);
        $this->postJson("/api/militaires/{$m->id}/mensurations", [
            'taille_vetement_id' => $this->taille('Vêtement', 'L'), 'pointure_id' => $this->taille('Pointure', '41'), 'tour_tete_id' => $this->taille('Tour de tête', '56'),
        ])->assertCreated();

        $this->getJson("/api/militaires/{$m->id}")->assertOk()
            ->assertJsonCount(2, 'mensurations')
            ->assertJsonPath('mensurations.0.taille_vetement.valeur', 'L')
            ->assertJsonPath('mensuration_actuelle.taille_vetement.valeur', 'L')
            ->assertJsonPath('mensuration_actuelle.tour_tete.valeur', '56');

        $this->getJson('/api/militaires?sans_mensuration=1')->assertJsonPath('total', 0);
    }

    public function test_statut_et_cloture(): void
    {
        $admin = $this->en(User::ROLE_ADMIN);
        $m = $this->recrue($this->cir);

        $this->patchJson("/api/militaires/{$m->id}/statut", ['statut' => 'mute'])->assertStatus(422)
            ->assertJsonPath('errors.motif.0', 'Indiquez le motif (mutation ou radiation).');

        // Un sac à dos en service interdit la clôture
        $bon = Dotation::create(['numero_bon' => 'BD-T-1', 'militaire_id' => $m->id, 'magasin_id' => Magasin::factory()->create()->id, 'type' => 'initiale', 'date' => now()->toDateString(), 'magasinier_id' => $admin->id]);
        $sac = DotationLigne::create(['dotation_id' => $bon->id, 'article_id' => Article::where('code', 'CAMP-SAC')->value('id'), 'quantite' => 1]);
        $this->patchJson("/api/militaires/{$m->id}/statut", ['statut' => 'radie', 'motif' => 'Fin de contrat'])->assertStatus(422)
            ->assertJsonPath('message', 'Clôture impossible : 1 article(s) à réintégrer encore en service (Sac à dos).');

        $sac->update(['etat' => 'reintegre']);
        $this->patchJson("/api/militaires/{$m->id}/statut", ['statut' => 'radie', 'motif' => 'Fin de contrat'])->assertOk()->assertJsonPath('statut', 'radie');

        // Radié : plus de mesures ; doté : pas de suppression
        $this->postJson("/api/militaires/{$m->id}/mensurations", ['taille_vetement_id' => $this->taille('Vêtement', 'M'), 'pointure_id' => null, 'tour_tete_id' => null])
            ->assertStatus(422)->assertJsonPath('message', 'Ce militaire est radié : plus de prise de mesures.');
        $this->deleteJson("/api/militaires/{$m->id}")->assertStatus(422)->assertJsonPath('message', 'Impossible : ce militaire a déjà reçu une dotation. Radiez-le plutôt.');

        $sans = $this->recrue($this->cir);
        $this->deleteJson("/api/militaires/{$sans->id}")->assertNoContent();
    }
}
