<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Services\LignesBon;
use App\Services\StockService;
use App\Support\Acces;
use Illuminate\Http\Request;

/**
 * Stock des magasins (contrat F3). Administrateur : tous les magasins ; magasinier : ses magasins (RG8).
 * Les sorties viennent des bons de dotation, les réintégrations des retours (pas de saisie directe ici).
 */
class StockController extends Controller
{
    public function etat(Request $request, Magasin $magasin)
    {
        Acces::magasin($request->user(), $magasin);

        return response()->json(StockService::etat($magasin));
    }

    public function entree(Request $request, Magasin $magasin)
    {
        return $this->bon($request, $magasin, MouvementStock::ENTREE);
    }

    public function reforme(Request $request, Magasin $magasin)
    {
        return $this->bon($request, $magasin, MouvementStock::REFORME);
    }

    public function seuils(Request $request, Magasin $magasin)
    {
        Acces::magasin($request->user(), $magasin);
        $request->validate(['lignes' => ['required', 'array']]);
        $lignes = LignesBon::valider($request->input('lignes'), 'seuil_alerte', 0, 10000);

        return response()->json(['modifies' => StockService::seuils($request->user(), $magasin, $lignes)]);
    }

    private function bon(Request $request, Magasin $magasin, string $type)
    {
        Acces::magasin($request->user(), $magasin);
        $request->merge(['reference' => mb_strtoupper(trim((string) $request->input('reference')))]);
        $entete = $request->validate([
            'reference' => ['required', 'string', 'max:50'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'motif' => [$type === MouvementStock::REFORME ? 'required' : 'nullable', 'string', 'max:255'],
            'lignes' => ['required', 'array'],
        ], ['date.before_or_equal' => 'La date ne peut pas être dans le futur.', 'motif.required' => 'Indiquez le motif de la réforme.']);
        $lignes = LignesBon::valider($request->input('lignes'));

        $mouvements = StockService::bon($type, $request->user(), $magasin, $entete['reference'], $entete['date'] ?? now()->toDateString(), $lignes, $entete['motif'] ?? null);

        return response()->json([
            'reference' => $entete['reference'],
            'mouvements' => collect($mouvements)->map(fn ($m) => StockService::presenterMouvement($m->load(['magasin', 'article', 'taille', 'user'])))->all(),
        ], 201);
    }
}
