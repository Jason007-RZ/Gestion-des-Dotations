<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catalogue des effets dotables (contrat F1). Lecture : tous ; écriture : administrateur. */
class ArticleController extends Controller
{
    public function index()
    {
        return response()->json(
            Article::with(['categorie:id,code,libelle', 'grilleTaille.tailles'])->orderBy('code')->get()
        );
    }

    public function store(Request $request)
    {
        $article = Article::create($this->valider($request));
        Audit::enregistrer('article_cree', "Article créé : {$article->code} {$article->designation}", $article);

        return response()->json($this->charger($article), 201);
    }

    public function update(Request $request, Article $article)
    {
        $data = $this->valider($request, $article);
        if ((int) ($data['grille_taille_id'] ?? 0) !== (int) ($article->grille_taille_id ?? 0) && ($raison = Usages::changementDeGrille($article))) {
            abort(422, $raison);
        }
        $article->update($data);
        Audit::enregistrer('article_modifie', "Article modifié : {$article->code} {$article->designation}", $article, $article->getChanges());

        return response()->json($this->charger($article));
    }

    public function destroy(Article $article)
    {
        if ($raison = Usages::article($article)) {
            abort(422, $raison);
        }
        Audit::enregistrer('article_supprime', "Article supprimé : {$article->code} {$article->designation}", $article);
        $article->stocks()->delete(); // lignes à zéro jamais mouvementées (vérifié par Usages)
        $article->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request, ?Article $actuel = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('articles', 'code')->ignore($actuel?->id)],
            'designation' => ['required', 'string', 'max:255'],
            'categorie_id' => ['required', 'integer', 'exists:categories_article,id'],
            'grille_taille_id' => ['nullable', 'integer', 'exists:grilles_taille,id'],
            'duree_vie_mois' => ['nullable', 'integer', 'min:1', 'max:240'],
            'a_reintegrer' => ['sometimes', 'boolean'],
            'actif' => ['sometimes', 'boolean'],
        ], [
            'code.unique' => 'Le code '.$request->input('code').' est déjà utilisé.',
            'categorie_id.required' => 'Choisissez une catégorie.',
        ]);

        return $data + ['grille_taille_id' => null, 'duree_vie_mois' => null];
    }

    private function charger(Article $a): Article
    {
        return $a->fresh(['categorie:id,code,libelle', 'grilleTaille.tailles']);
    }
}
