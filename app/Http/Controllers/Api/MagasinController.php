<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Magasin;
use App\Models\User;
use App\Support\Audit;
use App\Support\Usages;
use Illuminate\Http\Request;

class MagasinController extends Controller
{
    public function store(Request $request)
    {
        $magasin = Magasin::create($this->valider($request));
        Audit::enregistrer('magasin_cree', "Magasin créé : {$magasin->libelle}", $magasin);

        return response()->json($magasin->load('unite:id,code,libelle'), 201);
    }

    public function update(Request $request, Magasin $magasin)
    {
        $magasin->update($this->valider($request));
        Audit::enregistrer('magasin_modifie', "Magasin modifié : {$magasin->libelle}", $magasin);

        return response()->json($magasin->load('unite:id,code,libelle'));
    }

    public function destroy(Magasin $magasin)
    {
        if ($raison = Usages::magasin($magasin)) {
            abort(422, $raison);
        }
        Audit::enregistrer('magasin_supprime', "Magasin supprimé : {$magasin->libelle}", $magasin);
        $magasin->stocks()->delete();       // lignes à zéro jamais mouvementées (vérifié par Usages)
        $magasin->magasiniers()->detach();
        $magasin->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'unite_id' => ['present', 'nullable', 'integer', 'exists:unites,id'],
        ]);
    }

    public function rattacher(Request $request, Magasin $magasin)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);

        $utilisateur = User::findOrFail($data['user_id']);

        if (! $utilisateur->estMagasinier()) {
            return response()->json(['message' => 'Seul un compte de rôle magasinier peut être rattaché à un magasin.'], 422);
        }

        $magasin->utilisateurs()->syncWithoutDetaching([$utilisateur->id]);
        Audit::enregistrer('magasin_rattachement', "{$utilisateur->name} rattaché au magasin {$magasin->libelle} (RG8)", $magasin, ['user_id' => $utilisateur->id]);

        return response()->json($magasin->load('utilisateurs:id,name,email'));
    }

    /**
     * DELETE /api/magasins/{magasin}/rattacher/{user}
     */
    public function detacher(Magasin $magasin, User $user)
    {
        $magasin->utilisateurs()->detach($user->id);
        Audit::enregistrer('magasin_detachement', "{$user->name} détaché du magasin {$magasin->libelle} (RG8)", $magasin, ['user_id' => $user->id]);

        return response()->json(null, 204);
    }
}
