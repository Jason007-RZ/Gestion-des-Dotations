<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Militaire;
use App\Services\DotationService;
use App\Services\IndicateurService;
use App\Support\Acces;
use Illuminate\Http\Request;

/** Tableau de bord, espace du militaire, prévisions, paquetage (contrat F4 et F6). */
class IndicateurController extends Controller
{
    public function tableauDeBord(Request $request)
    {
        $f = $request->validate(['unite_id' => ['sometimes', 'nullable', 'integer']]);

        return response()->json(IndicateurService::tableauDeBord($request->user(), $f['unite_id'] ?? null));
    }

    public function monEspace(Request $request)
    {
        $militaire = Militaire::find($request->user()->militaire_id);
        abort_unless($militaire, 404, "Votre compte n'est relié à aucune fiche militaire.");

        return response()->json(IndicateurService::monEspace($militaire));
    }

    public function previsions(Request $request)
    {
        $f = $request->validate([
            'du' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'au' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            'unite_id' => ['sometimes', 'nullable', 'integer'],
        ], ['au.after_or_equal' => 'La fin de période doit suivre le début.']);
        $du = $f['du'] ?? now()->toDateString();
        $au = $f['au'] ?? DotationService::plusMois($du, 12);

        return response()->json(IndicateurService::previsions($request->user(), $du, $au, $f['unite_id'] ?? null));
    }

    /** GET /militaires/{id}/paquetage : mêmes droits que la fiche (chef : son unité ; militaire : lui-même). */
    public function paquetage(Request $request, Militaire $militaire)
    {
        Acces::militaire($request->user(), $militaire);

        return response()->json(DotationService::paquetage($militaire));
    }
}
