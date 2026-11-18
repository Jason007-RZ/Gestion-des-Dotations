<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taille;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Une valeur de taille : renommer, changer l'ordre d'affichage, supprimer. Administrateur. */
class TailleController extends Controller
{
    public function update(Request $request, Taille $taille)
    {
        if ($request->has('valeur')) {
            $request->merge(['valeur' => mb_strtoupper(trim((string) $request->input('valeur')))]);
        }
        $data = $request->validate([
            'valeur' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('tailles', 'valeur')->where('grille_taille_id', $taille->grille_taille_id)->ignore($taille->id)],
            'ordre' => ['sometimes', 'required', 'integer', 'min:0', 'max:999'],
        ], ['valeur.unique' => 'La taille '.$request->input('valeur').' existe déjà.']);

        $taille->update($data);
        Audit::enregistrer('referentiel', "Taille modifiée : {$taille->valeur}", $taille, $data);

        return response()->json($taille);
    }

    public function destroy(Taille $taille)
    {
        if ($raison = Usages::taille($taille)) {
            abort(422, $raison);
        }
        Audit::enregistrer('referentiel', "Taille supprimée : {$taille->valeur}", $taille);
        $taille->delete();

        return response()->json(null, 204);
    }
}
