<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Services\StockService;
use App\Support\Acces;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Journal des mouvements de stock (contrat F3). Magasinier : ses magasins seulement. */
class MouvementStockController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'magasin_id' => ['sometimes', 'nullable', 'integer', 'exists:magasins,id'],
            'type' => ['sometimes', 'nullable', Rule::in(MouvementStock::TYPES)],
            'article_id' => ['sometimes', 'nullable', 'integer'],
            'du' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'au' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:50'],
            'par_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        if (! empty($f['magasin_id'])) {
            Acces::magasin($user, Magasin::findOrFail($f['magasin_id']));
        }

        $page = MouvementStock::with(['magasin:id,libelle', 'article:id,code,designation', 'taille:id,valeur', 'user:id,name'])
            ->when($user->estMagasinier(), fn ($q) => $q->whereIn('magasin_id', $user->magasins()->pluck('magasins.id')))
            ->when(! empty($f['magasin_id']), fn ($q) => $q->where('magasin_id', $f['magasin_id']))
            ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))
            ->when(! empty($f['article_id']), fn ($q) => $q->where('article_id', $f['article_id']))
            ->when(! empty($f['du']), fn ($q) => $q->where('date', '>=', $f['du'].' 00:00:00'))
            ->when(! empty($f['au']), fn ($q) => $q->where('date', '<=', $f['au'].' 23:59:59'))
            ->when(! empty($f['reference']), fn ($q) => $q->where('reference', 'like', '%'.mb_strtoupper(trim($f['reference'])).'%'))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($f['par_page'] ?? 25);

        $page->getCollection()->transform(fn ($m) => StockService::presenterMouvement($m));

        return response()->json($page);
    }
}
