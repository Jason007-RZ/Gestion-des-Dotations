<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\MotDePasse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        return response()->json($query->with(['unite', 'magasins:magasins.id,magasins.libelle'])->get(['id', 'name', 'email', 'role', 'unite_id', 'two_factor_confirmed_at']));
    }

    /**
     * POST /api/users
     * Crée un compte. Selon le rôle :
     *  - chef_unite : unite_id requis
     *  - magasinier : magasin_ids optionnel, rattache directement (RG8)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => MotDePasse::regles(),
            'role' => ['required', Rule::in([
                User::ROLE_ADMIN, User::ROLE_MAGASINIER, User::ROLE_CHEF_UNITE, User::ROLE_MILITAIRE,
            ])],
            'unite_id' => ['required_if:role,chef_unite', 'nullable', 'exists:unites,id'],
            'magasin_ids' => ['sometimes', 'array'],
            'magasin_ids.*' => ['exists:magasins,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'unite_id' => $data['unite_id'] ?? null,
        ]);

        if ($data['role'] === User::ROLE_MAGASINIER && ! empty($data['magasin_ids'])) {
            $user->magasins()->sync($data['magasin_ids']);
        }

        Audit::enregistrer('utilisateur_cree', "Compte créé : {$user->name} ({$user->role})", $user, ['email' => $user->email, 'role' => $user->role]);

        return response()->json($user->load('unite', 'magasins'), 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load('unite', 'magasins'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => MotDePasse::regles(array_values(array_filter([
                (string) $request->input('email', $user->email),
                (string) $request->input('name', $user->name),
            ])), false),
            'role' => ['sometimes', Rule::in([
                User::ROLE_ADMIN, User::ROLE_MAGASINIER, User::ROLE_CHEF_UNITE, User::ROLE_MILITAIRE,
            ])],
            'unite_id' => ['sometimes', 'nullable', 'exists:unites,id'],
        ]);

        // Jamais de plateforme sans administrateur (sinon plus personne ne peut gérer les comptes).
        if (isset($data['role']) && $data['role'] !== User::ROLE_ADMIN && $this->estDernierAdministrateur($user)) {
            return response()->json(['message' => "Impossible : c'est le dernier compte administrateur."], 422);
        }

        if (isset($data['email'])) {
            $data['email'] = Str::lower(trim($data['email']));
        }
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $roleAvant = $user->role;
        $user->update($data);

        // Changement de rôle ou de mot de passe : les sessions ouvertes avec les anciens droits sont fermées.
        if (isset($data['password']) || $roleAvant !== $user->role) {
            $this->revoquerSessions($request, $user);
        }

        Audit::enregistrer('utilisateur_modifie', "Compte modifié : {$user->name}".($roleAvant !== $user->role ? " (rôle {$roleAvant} → {$user->role})" : '').(isset($data['password']) ? ' (mot de passe changé)' : ''), $user, ['champs' => array_values(array_diff(array_keys($data), ['password'])), 'mot_de_passe' => isset($data['password'])]);

        return response()->json($user->fresh(['unite', 'magasins']));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }

        if ($this->estDernierAdministrateur($user)) {
            return response()->json(['message' => "Impossible : c'est le dernier compte administrateur."], 422);
        }

        // Traçabilité (RG2/RG3) : un compte qui a enregistré des mouvements ne peut pas disparaître.
        if ($user->mouvements()->exists()) {
            return response()->json(['message' => "Impossible : ce compte a enregistré des mouvements (traçabilité). Changez son mot de passe ou son rôle plutôt que de le supprimer."], 422);
        }

        $user->revoquerJetons(); // révoque tous ses accès en cours
        Audit::enregistrer('utilisateur_supprime', "Compte supprimé : {$user->name} ({$user->role})", $user, ['email' => $user->email]);
        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * DELETE /api/users/{user}/2fa
     * Secours : l'utilisateur a perdu son téléphone ET ses codes de secours. L'administrateur retire la
     * double authentification (le compte redevient protégé par le seul mot de passe jusqu'à réactivation).
     */
    public function reinitialiserDoubleFacteur(Request $request, User $user)
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->revoquerSessions($request, $user);
        Audit::enregistrer('2fa_reinitialise', "Double authentification réinitialisée par l'administrateur : {$user->name}", $user);

        return response()->json(['message' => 'Double authentification retirée. Les sessions de ce compte ont été fermées.']);
    }

    private function estDernierAdministrateur(User $user): bool
    {
        return $user->estAdministrateur()
            && User::where('role', User::ROLE_ADMIN)->where('id', '!=', $user->id)->doesntExist();
    }

    /** Ferme les sessions du compte ; si l'administrateur modifie son propre compte, sa session en cours est conservée. */
    private function revoquerSessions(Request $request, User $user): void
    {
        if ($request->user()->is($user)) {
            $request->user()->revoquerJetons(garderCourant: true);
        } else {
            $user->revoquerJetons();
        }
    }
}
