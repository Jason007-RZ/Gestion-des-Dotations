<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CodesSecours;
use App\Support\DoubleFacteur;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Double authentification (TOTP) du compte connecté.
 * Toutes les actions exigent le mot de passe actuel : un jeton volé ne suffit pas à les modifier.
 */
class DoubleFacteurController extends Controller
{
    /** POST /api/2fa/preparer — génère un secret (pas encore actif tant qu'un code n'a pas été confirmé). */
    public function preparer(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->exigerMotDePasse($request);

        if ($user->aDoubleFacteur()) {
            return response()->json(['message' => 'La double authentification est déjà activée.'], 422);
        }

        $secret = Totp::genererSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth' => Totp::uri($secret, $user->email, (string) config('securite.double_facteur.emetteur')),
        ]);
    }

    /** POST /api/2fa/confirmer — vérifie un premier code, active la protection et remet les codes de secours. */
    public function confirmer(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if ($user->aDoubleFacteur() || ! $user->two_factor_secret) {
            return response()->json(['message' => "Aucune activation en cours. Recommencez à l'étape précédente."], 422);
        }

        $pas = Totp::verifier($user->two_factor_secret, $data['code'], null, (int) config('securite.double_facteur.tolerance', 1));
        if ($pas === null) {
            throw ValidationException::withMessages(['code' => ['Code incorrect. Vérifiez l\'heure de votre téléphone et réessayez.']]);
        }

        $codes = CodesSecours::generer((int) config('securite.double_facteur.codes_secours', 8));
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $pas,
            'two_factor_recovery_codes' => array_map(fn (string $c) => CodesSecours::empreinte($c, (string) config('app.key')), $codes),
        ])->save();

        $user->revoquerJetons(garderCourant: true);
        Audit::enregistrer('2fa_active', "Double authentification activée : {$user->name}", $user);

        return response()->json(['codes_secours' => $codes]);
    }

    /** POST /api/2fa/desactiver — mot de passe + un code (ou un code de secours). */
    public function desactiver(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->exigerMotDePasse($request);
        $this->exigerSecondFacteur($request);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        Audit::enregistrer('2fa_desactive', "Double authentification désactivée : {$user->name}", $user);

        return response()->json(['message' => 'Double authentification désactivée.']);
    }

    /** POST /api/2fa/codes-secours — nouveaux codes de secours (les anciens deviennent invalides). */
    public function regenererCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->exigerMotDePasse($request);
        $this->exigerSecondFacteur($request);

        $codes = CodesSecours::generer((int) config('securite.double_facteur.codes_secours', 8));
        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $c) => CodesSecours::empreinte($c, (string) config('app.key')), $codes),
        ])->save();

        Audit::enregistrer('2fa_codes_regeneres', "Codes de secours régénérés : {$user->name}", $user);

        return response()->json(['codes_secours' => $codes]);
    }

    private function exigerMotDePasse(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Mot de passe incorrect.']]);
        }
    }

    private function exigerSecondFacteur(Request $request): void
    {
        $user = $request->user();

        if (! $user->aDoubleFacteur()) {
            abort(422, "La double authentification n'est pas activée.");
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:12'],
            'code_secours' => ['nullable', 'string', 'max:20'],
        ]);

        if (DoubleFacteur::valider($user, $data['code'] ?? null, $data['code_secours'] ?? null) === null) {
            throw ValidationException::withMessages(['code' => ['Code incorrect.']]);
        }
    }
}
