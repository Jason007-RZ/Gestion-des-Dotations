<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\CategoriePersonnel;
use App\Models\TableauDotation;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de dotation (cœur du cahier) : droits de chaque catégorie de personnel.
 * Lecture : administrateur, magasinier, chef d'unité. Écriture : administrateur.
 */
class TableauDotationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['categorie_personnel_id' => ['sometimes', 'nullable', 'integer']]);

        return response()->json(
            TableauDotation::with(['article.grilleTaille.tailles', 'article.categorie:id,code,libelle'])
                ->when(! empty($data['categorie_personnel_id']), fn ($q) => $q->where('categorie_personnel_id', $data['categorie_personnel_id']))
                ->get()
                ->sortBy(fn ($l) => $l->article?->code)
                ->values()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'categorie_personnel_id' => ['required', 'integer', 'exists:categories_personnel,id'],
            'article_id' => ['required', 'integer', 'exists:articles,id'],
        ] + $this->reglesQuantites(), ['article_id.required' => 'Choisissez un article.']);

        $article = Article::findOrFail($data['article_id']);
        abort_unless($article->actif, 422, 'Cet article est désactivé : réactivez-le dans le catalogue.');
        abort_if(
            TableauDotation::where('categorie_personnel_id', $data['categorie_personnel_id'])->where('article_id', $article->id)->exists(),
            422,
            "« {$article->designation} » figure déjà dans le tableau de cette catégorie."
        );

        $ligne = TableauDotation::create($data);
        Audit::enregistrer('tableau_dotation', "Tableau de dotation : ajout de {$article->designation} ({$ligne->categorie->libelle})", $ligne, $data);

        return response()->json($ligne->load('article.grilleTaille.tailles'), 201);
    }

    public function update(Request $request, TableauDotation $tableauDotation)
    {
        $data = $request->validate($this->reglesQuantites());
        $tableauDotation->update($data);
        Audit::enregistrer('tableau_dotation', "Tableau de dotation modifié : {$tableauDotation->article->designation} ({$tableauDotation->categorie->libelle})", $tableauDotation, $data);

        return response()->json($tableauDotation->load('article.grilleTaille.tailles'));
    }

    public function destroy(TableauDotation $tableauDotation)
    {
        Audit::enregistrer('tableau_dotation', "Tableau de dotation : retrait de {$tableauDotation->article->designation} ({$tableauDotation->categorie->libelle})", $tableauDotation);
        $tableauDotation->delete();

        return response()->json(null, 204);
    }

    /** POST /tableau-dotation/copier : ajoute à « cible » les lignes de « source » ; les articles déjà présents ne changent pas. */
    public function copier(Request $request)
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:categories_personnel,id', 'different:cible_id'],
            'cible_id' => ['required', 'integer', 'exists:categories_personnel,id'],
        ], ['source_id.different' => 'Choisissez une autre catégorie que celle affichée.']);

        $resultat = DB::transaction(function () use ($data) {
            $deja = TableauDotation::where('categorie_personnel_id', $data['cible_id'])->lockForUpdate()->pluck('article_id')->all();
            $copiees = 0;
            $ignorees = 0;
            foreach (TableauDotation::where('categorie_personnel_id', $data['source_id'])->get() as $l) {
                if (in_array($l->article_id, $deja, true)) {
                    $ignorees++;

                    continue;
                }
                TableauDotation::create([
                    'categorie_personnel_id' => $data['cible_id'], 'article_id' => $l->article_id,
                    'quantite_reglementaire' => $l->quantite_reglementaire, 'periodicite_mois' => $l->periodicite_mois,
                ]);
                $copiees++;
            }

            return compact('copiees', 'ignorees');
        });

        $source = CategoriePersonnel::find($data['source_id'])->libelle;
        $cible = CategoriePersonnel::find($data['cible_id'])->libelle;
        Audit::enregistrer('tableau_dotation', "Tableau de dotation copié de « {$source} » vers « {$cible} » ({$resultat['copiees']} ligne(s))", null, $data + $resultat);

        return response()->json($resultat);
    }

    private function reglesQuantites(): array
    {
        return [
            'quantite_reglementaire' => ['required', 'integer', 'min:1', 'max:99'],
            'periodicite_mois' => ['present', 'nullable', 'integer', 'min:1', 'max:240'],
        ];
    }
}
