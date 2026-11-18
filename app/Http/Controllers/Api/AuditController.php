<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * GET /api/audit?action=&q=&du=&au=&page=
     * Réservé à l'administrateur (routes/api.php). Lecture seule.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:60'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'du' => ['sometimes', 'nullable', 'date'],
            'au' => ['sometimes', 'nullable', 'date'],
        ]);

        $query = AuditLog::query()->orderByDesc('id');

        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }
        if (! empty($data['q'])) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($w) => $w->where('resume', 'like', $q)->orWhere('user_nom', 'like', $q));
        }
        if (! empty($data['du'])) {
            $query->where('created_at', '>=', $data['du'].' 00:00:00');
        }
        if (! empty($data['au'])) {
            $query->where('created_at', '<=', $data['au'].' 23:59:59');
        }

        return response()->json($query->paginate(25));
    }
}
