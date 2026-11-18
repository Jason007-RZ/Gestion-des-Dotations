<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Grades, rattachés à une catégorie de personnel (qui détermine le tableau de dotation). Administrateur. */
class GradeController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->valider($request);
        $data['ordre'] ??= (int) Grade::max('ordre') + 1;
        $g = Grade::create($data);
        Audit::enregistrer('referentiel', "Grade créé : {$g->libelle}", $g);

        return response()->json($g, 201);
    }

    public function update(Request $request, Grade $grade)
    {
        $grade->update($this->valider($request, $grade));
        Audit::enregistrer('referentiel', "Grade modifié : {$grade->libelle}", $grade, $grade->getChanges());

        return response()->json($grade);
    }

    public function destroy(Grade $grade)
    {
        if ($raison = Usages::grade($grade)) {
            abort(422, $raison);
        }
        Audit::enregistrer('referentiel', "Grade supprimé : {$grade->libelle}", $grade);
        $grade->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request, ?Grade $actuel = null): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:255', Rule::unique('grades', 'libelle')->ignore($actuel?->id)],
            'categorie_personnel_id' => ['required', 'integer', 'exists:categories_personnel,id'],
            'ordre' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'libelle.unique' => 'Le grade « '.$request->input('libelle').' » existe déjà.',
            'categorie_personnel_id.required' => 'Choisissez une catégorie de personnel.',
        ]);
    }
}
