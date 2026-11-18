<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dotation;
use App\Models\Magasin;
use App\Models\Militaire;
use App\Models\User;
use App\Services\DotationService;
use App\Services\LignesBon;
use App\Support\Acces;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bons de dotation (contrat F4). Création : administrateur et magasinier (depuis ses magasins).
 * Consultation : administrateur et magasinier (tous), chef d'unité (son unité), militaire (les siens).
 */
class DotationController extends Controller
{
    public function proposition(Request $request)
    {
        $f = $request->validate([
            'militaire_id' => ['required', 'integer', 'exists:militaires,id'],
            'magasin_id' => ['required', 'integer', 'exists:magasins,id'],
            'type' => ['sometimes', Rule::in([Dotation::INITIALE, Dotation::RENOUVELLEMENT])],
        ]);
        $magasin = Magasin::findOrFail($f['magasin_id']);
        Acces::magasin($request->user(), $magasin);

        return response()->json(DotationService::proposition(Militaire::findOrFail($f['militaire_id']), $magasin, $f['type'] ?? Dotation::INITIALE));
    }

    public function store(Request $request)
    {
        $f = $request->validate([
            'militaire_id' => ['required', 'integer', 'exists:militaires,id'],
            'magasin_id' => ['required', 'integer', 'exists:magasins,id'],
            'type' => ['required', Rule::in(Dotation::TYPES)],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'observation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lignes' => ['required', 'array'],
        ], ['militaire_id.required' => 'Choisissez un militaire.']);
        $magasin = Magasin::findOrFail($f['magasin_id']);
        Acces::magasin($request->user(), $magasin);
        $lignes = LignesBon::valider($request->input('lignes'));

        $bon = DotationService::creer($request->user(), Militaire::findOrFail($f['militaire_id']), $magasin, $f['type'], $f['date'] ?? null, $f['observation'] ?? null, $lignes);

        return response()->json(DotationService::complet($bon), 201);
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in(Dotation::TYPES)],
            'magasin_id' => ['sometimes', 'nullable', 'integer'],
            'militaire_id' => ['sometimes', 'nullable', 'integer'],
            'du' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'au' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'par_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = self::visibles($request->user())
            ->with(['militaire.grade:id,libelle', 'militaire.unite:id,code,libelle', 'magasin:id,libelle', 'magasinier:id,name', 'lignes'])
            ->when(! empty($f['type']), fn ($r) => $r->where('type', $f['type']))
            ->when(! empty($f['magasin_id']), fn ($r) => $r->where('magasin_id', $f['magasin_id']))
            ->when(! empty($f['militaire_id']), fn ($r) => $r->where('militaire_id', $f['militaire_id']))
            ->when(! empty($f['du']), fn ($r) => $r->where('date', '>=', $f['du']))
            ->when(! empty($f['au']), fn ($r) => $r->where('date', '<=', $f['au']))
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('numero_bon', 'like', "%{$q}%")
                ->orWhereHas('militaire', fn ($m) => $m->where('matricule', 'like', "%{$q}%")->orWhere('nom', 'like', "%{$q}%")->orWhere('prenoms', 'like', "%{$q}%"))))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($f['par_page'] ?? 20);

        $page->getCollection()->transform(fn ($b) => DotationService::resume($b));

        return response()->json($page);
    }

    public function show(Request $request, Dotation $dotation)
    {
        abort_unless(self::visibles($request->user())->whereKey($dotation->id)->exists(), 403, 'Accès refusé : ce bon ne vous concerne pas.');

        return response()->json(DotationService::complet($dotation));
    }

    /** Bons visibles selon le rôle. */
    public static function visibles(User $user): Builder
    {
        return Dotation::query()
            ->when($user->estChefUnite(), fn ($q) => $q->whereHas('militaire', fn ($m) => $m->where('unite_id', $user->unite_id)))
            ->when($user->estMilitaire(), fn ($q) => $q->where('militaire_id', $user->militaire_id ?? 0));
    }
}
