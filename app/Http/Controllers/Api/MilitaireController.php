<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dotation;
use App\Models\Militaire;
use App\Support\Acces;
use App\Support\Audit;
use App\Support\Personnel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Militaires (contrat F2). Lecture : administrateur, magasinier, chef d'unité (son unité).
 * Création, modification, statut et suppression : administrateur.
 */
class MilitaireController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'unite_id' => ['sometimes', 'nullable', 'integer'],
            'statut' => ['sometimes', 'nullable', Rule::in(Militaire::STATUTS)],
            'categorie_personnel_id' => ['sometimes', 'nullable', 'integer'],
            'sans_mensuration' => ['sometimes', 'nullable', 'boolean'],
            'par_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(Personnel::liste($request->user(), $f)->paginate($f['par_page'] ?? 20));
    }

    /** Fiche : le militaire et l'historique de ses mesures (la plus récente d'abord, avec leur auteur). */
    public function show(Request $request, Militaire $militaire)
    {
        Acces::militaire($request->user(), $militaire); // chef d'unité : son unité seulement

        return response()->json($this->fiche($militaire));
    }

    public function store(Request $request)
    {
        $militaire = Militaire::create($this->valider($request) + ['statut' => Militaire::STATUT_ACTIF]);
        Audit::enregistrer('militaire_cree', "Militaire créé : {$militaire->matricule} {$militaire->nom} {$militaire->prenoms}", $militaire);

        return response()->json($militaire->load(Personnel::RELATIONS), 201);
    }

    public function update(Request $request, Militaire $militaire)
    {
        $militaire->update($this->valider($request, $militaire));
        Audit::enregistrer('militaire_modifie', "Fiche modifiée : {$militaire->matricule} {$militaire->nom} {$militaire->prenoms}", $militaire, $militaire->getChanges());

        return response()->json($militaire->load(Personnel::RELATIONS));
    }

    /** PATCH /militaires/{id}/statut : actif, muté, radié. La clôture exige d'avoir rendu les articles à réintégrer. */
    public function statut(Request $request, Militaire $militaire)
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in(Militaire::STATUTS)],
            'motif' => ['required_unless:statut,'.Militaire::STATUT_ACTIF, 'nullable', 'string', 'max:255'],
        ], ['motif.required_unless' => 'Indiquez le motif (mutation ou radiation).']);

        if ($data['statut'] !== Militaire::STATUT_ACTIF && ($raison = Personnel::raisonClotureImpossible($militaire))) {
            abort(422, $raison);
        }

        $avant = $militaire->statut;
        $militaire->update(['statut' => $data['statut']]);
        Audit::enregistrer('statut_militaire', "Statut de {$militaire->matricule} {$militaire->nom} : {$avant} → {$data['statut']}".(! empty($data['motif']) ? " ({$data['motif']})" : ''), $militaire, $data + ['ancien_statut' => $avant]);

        return response()->json($militaire->load(Personnel::RELATIONS));
    }

    public function destroy(Militaire $militaire)
    {
        abort_if(Dotation::where('militaire_id', $militaire->id)->exists(), 422, 'Impossible : ce militaire a déjà reçu une dotation. Radiez-le plutôt.');

        Audit::enregistrer('militaire_supprime', "Fiche supprimée : {$militaire->matricule} {$militaire->nom} {$militaire->prenoms}", $militaire);
        $militaire->delete(); // ses mensurations partent avec (clé étrangère en cascade)

        return response()->json(null, 204);
    }

    private function fiche(Militaire $m): array
    {
        $m->load(Personnel::RELATIONS);
        $m->load(['mensurations.tailleVetement:id,valeur', 'mensurations.pointure:id,valeur', 'mensurations.tourTete:id,valeur', 'mensurations.saisiePar:id,name']);

        $donnees = $m->toArray();
        $donnees['mensurations'] = $m->mensurations->map(fn ($x) => [
            'id' => $x->id,
            'date_mesure' => $x->date_mesure?->toDateString(),
            'taille_vetement' => $x->tailleVetement?->only(['id', 'valeur']),
            'pointure' => $x->pointure?->only(['id', 'valeur']),
            'tour_tete' => $x->tourTete?->only(['id', 'valeur']),
            'saisie_par' => $x->saisiePar ? ['id' => $x->saisiePar->id, 'name' => $x->saisiePar->name] : null,
        ])->values();

        return $donnees;
    }

    private function valider(Request $request, ?Militaire $actuel = null): array
    {
        $request->merge([
            'matricule' => mb_strtoupper(trim((string) $request->input('matricule'))),
            'nom' => mb_strtoupper(trim((string) $request->input('nom'))),
            'prenoms' => trim((string) $request->input('prenoms')),
        ]);

        return $request->validate([
            'matricule' => ['required', 'string', 'max:30', Rule::unique('militaires', 'matricule')->ignore($actuel?->id)],
            'nom' => ['required', 'string', 'max:255'],
            'prenoms' => ['required', 'string', 'max:255'],
            'grade_id' => ['required', 'integer', 'exists:grades,id'],
            'unite_id' => ['required', 'integer', 'exists:unites,id'],
            'date_incorporation' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'matricule.unique' => 'Le matricule '.$request->input('matricule').' est déjà utilisé.',
            'grade_id.required' => 'Choisissez un grade.',
            'unite_id.required' => 'Choisissez une unité.',
            'date_incorporation.before_or_equal' => "La date d'incorporation ne peut pas être dans le futur.",
        ]);
    }
}
