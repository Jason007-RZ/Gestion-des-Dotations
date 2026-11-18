<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategorieArticle;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catégories d'articles (HAB, COI, CHA, PEQ, CAMP, COU…). Administrateur. */
class CategorieArticleController extends Controller
{
    public function store(Request $request)
    {
        $c = CategorieArticle::create($this->valider($request));
        Audit::enregistrer('referentiel', "Catégorie d'articles créée : {$c->code} {$c->libelle}", $c);

        return response()->json($c, 201);
    }

    public function update(Request $request, CategorieArticle $categorieArticle)
    {
        $categorieArticle->update($this->valider($request, $categorieArticle));
        Audit::enregistrer('referentiel', "Catégorie d'articles modifiée : {$categorieArticle->code} {$categorieArticle->libelle}", $categorieArticle);

        return response()->json($categorieArticle);
    }

    public function destroy(CategorieArticle $categorieArticle)
    {
        if ($raison = Usages::categorieArticle($categorieArticle)) {
            abort(422, $raison);
        }
        Audit::enregistrer('referentiel', "Catégorie d'articles supprimée : {$categorieArticle->code}", $categorieArticle);
        $categorieArticle->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request, ?CategorieArticle $actuel = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('categories_article', 'code')->ignore($actuel?->id)],
            'libelle' => ['required', 'string', 'max:255'],
        ], ['code.unique' => 'Le code '.$request->input('code').' est déjà utilisé.']);
    }
}
