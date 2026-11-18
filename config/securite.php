<?php

/*
|--------------------------------------------------------------------------
| Paramètres de sécurité de l'application
|--------------------------------------------------------------------------
| Chaque valeur peut être surchargée dans .env (variables SECURITE_*).
| Vérifier la configuration d'un poste : php artisan securite:verifier
*/

return [

    // Adresse du frontend : sert à fabriquer le lien du courriel « mot de passe oublié ».
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Durée de vie maximale d'un jeton d'accès, en minutes (8 h = une journée de travail).
    'jeton_duree_minutes' => (int) env('SECURITE_JETON_DUREE', 480),

    // Un jeton inutilisé pendant ce délai est refusé par le serveur (le frontend déconnecte déjà à 15 min).
    'inactivite_minutes' => (int) env('SECURITE_INACTIVITE', 30),

    // Nombre d'appareils connectés en même temps pour un même compte (les plus anciens sont révoqués).
    'jetons_max_par_compte' => (int) env('SECURITE_JETONS_MAX', 5),

    // Politique de mot de passe (NIST SP 800-63B-4 : la longueur prime sur la complexité).
    'mdp_min' => (int) env('SECURITE_MDP_MIN', 15),

    // Refuse les mots de passe présents dans des fuites publiques (service Have I Been Pwned, anonymisé
    // par k-anonymat). Nécessite un accès Internet sortant : désactivé par défaut.
    'verifier_fuites' => (bool) env('SECURITE_VERIFIER_FUITES', false),

    // Échecs de connexion tolérés par fenêtre (puis blocage temporaire, HTTP 429).
    'connexion' => [
        'fenetre_minutes' => 15,
        'echecs_email_ip' => 5,   // même compte depuis la même adresse IP
        'echecs_email' => 15,     // même compte, toutes adresses confondues
        'echecs_ip' => 40,        // même adresse IP, tous comptes confondus
    ],

    // Authentification à deux facteurs (TOTP, RFC 6238) : Google Authenticator, Aegis, FreeOTP, etc.
    'double_facteur' => [
        'emetteur' => env('SECURITE_2FA_EMETTEUR', 'Dotations Militaires'),
        'tolerance' => 1,         // ± 1 pas de 30 s (dérive d'horloge du téléphone)
        'defi_minutes' => 5,      // durée de validité de l'étape « saisir le code »
        'essais_par_defi' => 5,
        'codes_secours' => 8,
    ],

    // Requêtes par minute et par utilisateur (ou par adresse IP avant connexion).
    'limites' => [
        'api' => (int) env('SECURITE_LIMITE_API', 120),
        'pdf' => (int) env('SECURITE_LIMITE_PDF', 20),
    ],
];
