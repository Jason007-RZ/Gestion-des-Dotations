<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mensuration;
use App\Models\Militaire;
use App\Models\Taille;
use App\Support\Audit;
use App\Support\Referentiels;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /militaires/{id}/mensurations (administrateur, magasinier) : une nouvelle prise de mesures.
 * La plus récente préremplit les tailles des bons de dotation (cahier §3).
 */
class MensurationController extends Controller
{
    private const MESURES = [
        'taille_vetement_id' => ['taille_vetement', 'Vêtement'],
        'pointure_id' => ['pointure', 'Pointure'],
        'tour_tete_id' => ['tour_tete', 'Tour de tête'],
    ];

    public function store(Request $request, Militaire $militaire)
    {
        abort_if($militaire->statut === Militaire::STATUT_RADIE, 422, 'Ce militaire est radié : plus de prise de mesures.');

        $data = $request->validate([
            'date_mesure' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'taille_vetement_id' => ['present', 'nullable', 'integer'],
            'pointure_id' => ['present', 'nullable', 'integer'],
            'tour_tete_id' => ['present', 'nullable', 'integer'],
        ], ['date_mesure.before_or_equal' => 'La date de mesure ne peut pas être dans le futur.']);

        if (collect(array_keys(self::MESURES))->every(fn ($c) => empty($data[$c]))) {
            throw ValidationException::withMessages(['taille_vetement_id' => 'Indiquez au moins une mesure.']);
        }

        // Chaque taille doit appartenir à la grille de sa mesure (vêtement, pointure, tour de tête)
        $grilles = Referentiels::grillesMensuration();
        foreach (self::MESURES as $champ => [$cle, $nom]) {
            if (empty($data[$champ])) {
                continue;
            }
            $taille = Taille::find($data[$champ]);
            if (! $taille || (int) $taille->grille_taille_id !== (int) $grilles[$cle]) {
                throw ValidationException::withMessages([$champ => 'La taille '.($taille->valeur ?? $data[$champ])." n'appartient pas à la grille {$nom}."]);
            }
        }

        $mesure = Mensuration::create([
            'militaire_id' => $militaire->id,
            'date_mesure' => $data['date_mesure'] ?? now()->toDateString(),
            'taille_vetement_id' => $data['taille_vetement_id'] ?: null,
            'pointure_id' => $data['pointure_id'] ?: null,
            'tour_tete_id' => $data['tour_tete_id'] ?: null,
            'user_id' => $request->user()->id,
        ])->load(['tailleVetement:id,valeur', 'pointure:id,valeur', 'tourTete:id,valeur']);

        $resume = collect([$mesure->tailleVetement?->valeur, $mesure->pointure?->valeur, $mesure->tourTete?->valeur])->map(fn ($v) => $v ?? '—')->implode(' · ');
        Audit::enregistrer('mensuration', "Mesures de {$militaire->matricule} {$militaire->nom} : {$resume}", $militaire, ['mensuration_id' => $mesure->id]);

        return response()->json([
            'id' => $mesure->id,
            'date_mesure' => $mesure->date_mesure->toDateString(),
            'taille_vetement' => $mesure->tailleVetement?->only(['id', 'valeur']),
            'pointure' => $mesure->pointure?->only(['id', 'valeur']),
            'tour_tete' => $mesure->tourTete?->only(['id', 'valeur']),
            'saisie_par' => ['id' => $request->user()->id, 'name' => $request->user()->name],
        ], 201);
    }
}
