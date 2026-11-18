<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriePersonnel;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catégories de personnel (recrue, militaire du rang, sous-officier, officier). Administrateur. */
class CategoriePersonnelController extends Controller
{
    public function store(Request $request)
    {
        $c = CategoriePersonnel::create($this->valider($request));
        Audit::enregistrer('referentiel', "Catégorie de personnel créée : {$c->libelle}", $c);

        return response()->json($c->load('grades'), 201);
    }

    public function update(Request $request, CategoriePersonnel $categoriePersonnel)
    {
        $categoriePersonnel->update($this->valider($request, $categoriePersonnel));
        Audit::enregistrer('referentiel', "Catégorie de personnel renommée : {$categoriePersonnel->libelle}", $categoriePersonnel);

        return response()->json($categoriePersonnel->load('grades'));
    }

    public function destroy(CategoriePersonnel $categoriePersonnel)
    {
        if ($raison = Usages::categoriePersonnel($categoriePersonnel)) {
            abort(422, $raison);
        }
        Audit::enregistrer('referentiel', "Catégorie de personnel supprimée : {$categoriePersonnel->libelle}", $categoriePersonnel);
        $categoriePersonnel->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request, ?CategoriePersonnel $actuel = null): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:255', Rule::unique('categories_personnel', 'libelle')->ignore($actuel?->id)],
        ], ['libelle.unique' => 'La catégorie « '.$request->input('libelle').' » existe déjà.']);
    }
}
