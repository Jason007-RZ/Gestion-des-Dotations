<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unite;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Unités (régiments et services). Administrateur. La liste est dans GET /referentiels. */
class UniteController extends Controller
{
    public function store(Request $request)
    {
        $u = Unite::create($this->valider($request));
        Audit::enregistrer('unite_creee', "Unité créée : {$u->code} {$u->libelle}", $u);

        return response()->json($u, 201);
    }

    public function update(Request $request, Unite $unite)
    {
        $unite->update($this->valider($request, $unite));
        Audit::enregistrer('unite_modifiee', "Unité modifiée : {$unite->code} {$unite->libelle}", $unite);

        return response()->json($unite);
    }

    public function destroy(Unite $unite)
    {
        if ($raison = Usages::unite($unite)) {
            abort(422, $raison);
        }
        Audit::enregistrer('unite_supprimee', "Unité supprimée : {$unite->code} {$unite->libelle}", $unite);
        $unite->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request, ?Unite $actuel = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('unites', 'code')->ignore($actuel?->id)],
            'libelle' => ['required', 'string', 'max:255'],
        ], ['code.unique' => 'Le code '.$request->input('code').' est déjà utilisé.']);
    }
}
