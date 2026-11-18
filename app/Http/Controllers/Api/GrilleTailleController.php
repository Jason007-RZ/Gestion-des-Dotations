<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GrilleTaille;
use App\Models\Taille;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Grilles de taille (vêtement, pointure, tour de tête…) et leurs valeurs. Administrateur. */
class GrilleTailleController extends Controller
{
    public function store(Request $request)
    {
        $g = GrilleTaille::create($this->valider($request));
        Audit::enregistrer('referentiel', "Grille de taille créée : {$g->libelle}", $g);

        return response()->json($g->load('tailles'), 201);
    }

    public function update(Request $request, GrilleTaille $grilleTaille)
    {
        $grilleTaille->update($this->valider($request, $grilleTaille));
        Audit::enregistrer('referentiel', "Grille de taille renommée : {$grilleTaille->libelle}", $grilleTaille);

        return response()->json($grilleTaille->load('tailles'));
    }

    public function destroy(GrilleTaille $grilleTaille)
    {
        if ($raison = Usages::grille($grilleTaille->load('tailles'))) {
            abort(422, $raison);
        }
        Audit::enregistrer('referentiel', "Grille de taille supprimée : {$grilleTaille->libelle}", $grilleTaille);
        $grilleTaille->delete(); // ses tailles partent avec (clé étrangère en cascade)

        return response()->json(null, 204);
    }

    /** POST /grilles-taille/{id}/tailles : ajoute une valeur, à la suite des existantes par défaut. */
    public function ajouterTaille(Request $request, GrilleTaille $grilleTaille)
    {
        $request->merge(['valeur' => mb_strtoupper(trim((string) $request->input('valeur')))]);
        $data = $request->validate([
            'valeur' => ['required', 'string', 'max:20', Rule::unique('tailles', 'valeur')->where('grille_taille_id', $grilleTaille->id)],
            'ordre' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        ], ['valeur.unique' => 'La taille '.$request->input('valeur')." existe déjà dans « {$grilleTaille->libelle} »."]);

        $taille = DB::transaction(function () use ($grilleTaille, $data) {
            $ordre = $data['ordre'] ?? ((int) Taille::where('grille_taille_id', $grilleTaille->id)->lockForUpdate()->max('ordre') + 1);

            return Taille::create(['grille_taille_id' => $grilleTaille->id, 'valeur' => $data['valeur'], 'ordre' => $ordre]);
        });
        Audit::enregistrer('referentiel', "Taille {$taille->valeur} ajoutée à « {$grilleTaille->libelle} »", $taille);

        return response()->json($taille, 201);
    }

    private function valider(Request $request, ?GrilleTaille $actuel = null): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:255', Rule::unique('grilles_taille', 'libelle')->ignore($actuel?->id)],
        ], ['libelle.unique' => 'La grille « '.$request->input('libelle').' » existe déjà.']);
    }
}
