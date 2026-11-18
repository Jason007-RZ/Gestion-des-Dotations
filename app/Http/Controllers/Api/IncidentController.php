<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DotationLigne;
use App\Models\Incident;
use App\Models\Magasin;
use App\Models\Militaire;
use App\Models\User;
use App\Services\DotationService;
use App\Support\Acces;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Incidents (perte, détérioration avec PV), remplacement et réintégration (contrat F5).
 * Actions : administrateur et magasinier. Consultation : chef d'unité (son unité), militaire (les siens).
 */
class IncidentController extends Controller
{
    public function store(Request $request, DotationLigne $dotationLigne)
    {
        $request->merge(['pv_numero' => mb_strtoupper(trim((string) $request->input('pv_numero')))]);
        $data = $request->validate([
            'type' => ['required', Rule::in(Incident::TYPES)],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pv_numero' => ['required', 'string', 'max:30', 'unique:incidents,pv_numero'],
            'imputable' => ['required', 'boolean'],
            'quantite' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'observation' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'type.required' => 'Choisissez perte ou détérioration.',
            'pv_numero.unique' => 'Le PV '.$request->input('pv_numero').' est déjà enregistré.',
            'imputable.required' => "Indiquez si l'incident est imputable au militaire.",
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        $incident = DotationService::declarerIncident($request->user(), $dotationLigne, $data);

        return response()->json(DotationService::incident($incident), 201);
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in(Incident::TYPES)],
            'imputable' => ['sometimes', 'nullable', 'boolean'],
            'a_remplacer' => ['sometimes', 'nullable', 'boolean'],
            'militaire_id' => ['sometimes', 'nullable', 'integer'],
            'par_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = self::visibles($request->user())
            ->when(! empty($f['type']), fn ($r) => $r->where('type', $f['type']))
            ->when(isset($f['imputable']) && $f['imputable'] !== null, fn ($r) => $r->where('imputable', (bool) $f['imputable']))
            ->when(! empty($f['a_remplacer']), fn ($r) => $r->whereDoesntHave('remplacement'))
            ->when(! empty($f['militaire_id']), fn ($r) => $r->whereHas('ligne.dotation', fn ($d) => $d->where('militaire_id', $f['militaire_id'])))
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('pv_numero', 'like', "%{$q}%")
                ->orWhereHas('ligne.dotation.militaire', fn ($m) => $m->where('matricule', 'like', "%{$q}%")->orWhere('nom', 'like', "%{$q}%")->orWhere('prenoms', 'like', "%{$q}%"))))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($f['par_page'] ?? 20);

        $page->getCollection()->transform(fn ($i) => DotationService::incident($i));

        return response()->json($page);
    }

    public function show(Request $request, Incident $incident)
    {
        abort_unless(self::visibles($request->user())->whereKey($incident->id)->exists(), 403, 'Accès refusé.');

        return response()->json(DotationService::incident($incident));
    }

    public function remplacement(Request $request, Incident $incident)
    {
        $f = $request->validate([
            'magasin_id' => ['required', 'integer', 'exists:magasins,id'],
            'taille_id' => ['sometimes', 'nullable', 'integer'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'observation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $magasin = Magasin::findOrFail($f['magasin_id']);
        Acces::magasin($request->user(), $magasin);

        $bon = DotationService::remplacer($request->user(), $incident, $magasin, $f['taille_id'] ?? null, $f['date'] ?? null, $f['observation'] ?? null);

        return response()->json(DotationService::complet($bon), 201);
    }

    /** POST /militaires/{id}/reintegration : retour au magasin de lignes en service. */
    public function reintegration(Request $request, Militaire $militaire)
    {
        $f = $request->validate([
            'magasin_id' => ['required', 'integer', 'exists:magasins,id'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*' => ['integer', 'distinct'],
        ], ['lignes.required' => 'Choisissez au moins un article à réintégrer.', 'lignes.min' => 'Choisissez au moins un article à réintégrer.']);
        $magasin = Magasin::findOrFail($f['magasin_id']);
        Acces::magasin($request->user(), $magasin);

        return response()->json(DotationService::reintegrer($request->user(), $militaire, $magasin, $f['date'] ?? null, $f['lignes']), 201);
    }

    private static function visibles(User $user): Builder
    {
        return Incident::query()
            ->when($user->estChefUnite(), fn ($q) => $q->whereHas('ligne.dotation.militaire', fn ($m) => $m->where('unite_id', $user->unite_id)))
            ->when($user->estMilitaire(), fn ($q) => $q->whereHas('ligne.dotation', fn ($d) => $d->where('militaire_id', $user->militaire_id ?? 0)));
    }
}
