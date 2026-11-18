<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategorieArticleController;
use App\Http\Controllers\Api\CategoriePersonnelController;
use App\Http\Controllers\Api\DotationController;
use App\Http\Controllers\Api\DoubleFacteurController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GrilleTailleController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\IndicateurController;
use App\Http\Controllers\Api\MagasinController;
use App\Http\Controllers\Api\MensurationController;
use App\Http\Controllers\Api\MilitaireController;
use App\Http\Controllers\Api\MouvementStockController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TableauDotationController;
use App\Http\Controllers\Api\TailleController;
use App\Http\Controllers\Api\UniteController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| API de la refonte (cahier des charges officiel). Contrat : API-REFONTE.md (frontend).
| Rôles : administrateur, magasinier, chef_unite, militaire (middleware « role »).
*/

// --- Routes publiques (chacune avec sa limitation de débit, voir AppServiceProvider) ---
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:connexion');
Route::post('/login/2fa', [AuthController::class, 'loginDoubleFacteur'])->middleware('throttle:connexion');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:enrolement');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:mot-de-passe');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:mot-de-passe')->name('password.reset');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // --- Session et sécurité du compte ---
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/password', [AuthController::class, 'changerMotDePasse'])->middleware('throttle:5,1');
    Route::post('/me/sessions/revoquer', [AuthController::class, 'revoquerAutresSessions'])->middleware('throttle:5,1');
    Route::middleware('throttle:6,1')->prefix('2fa')->group(function () {
        Route::post('/preparer', [DoubleFacteurController::class, 'preparer']);
        Route::post('/confirmer', [DoubleFacteurController::class, 'confirmer']);
        Route::post('/desactiver', [DoubleFacteurController::class, 'desactiver']);
        Route::post('/codes-secours', [DoubleFacteurController::class, 'regenererCodes']);
    });

    // ===================================================================== F1 : référentiels
    // Lecture : tous les rôles connectés
    Route::get('/referentiels', [ReferentielController::class, 'index']);
    Route::get('/articles', [ArticleController::class, 'index']);

    // Tableau de dotation : lecture pour l'administrateur, le magasinier et le chef d'unité
    Route::get('/tableau-dotation', [TableauDotationController::class, 'index'])->middleware('role:administrateur,magasinier,chef_unite');

    // Écriture : administrateur
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/articles', [ArticleController::class, 'store']);
        Route::put('/articles/{article}', [ArticleController::class, 'update']);
        Route::delete('/articles/{article}', [ArticleController::class, 'destroy']);

        Route::post('/tableau-dotation/copier', [TableauDotationController::class, 'copier']);
        Route::post('/tableau-dotation', [TableauDotationController::class, 'store']);
        Route::put('/tableau-dotation/{tableauDotation}', [TableauDotationController::class, 'update']);
        Route::delete('/tableau-dotation/{tableauDotation}', [TableauDotationController::class, 'destroy']);

        Route::post('/categories-article', [CategorieArticleController::class, 'store']);
        Route::put('/categories-article/{categorieArticle}', [CategorieArticleController::class, 'update']);
        Route::delete('/categories-article/{categorieArticle}', [CategorieArticleController::class, 'destroy']);

        Route::post('/grilles-taille', [GrilleTailleController::class, 'store']);
        Route::put('/grilles-taille/{grilleTaille}', [GrilleTailleController::class, 'update']);
        Route::delete('/grilles-taille/{grilleTaille}', [GrilleTailleController::class, 'destroy']);
        Route::post('/grilles-taille/{grilleTaille}/tailles', [GrilleTailleController::class, 'ajouterTaille']);
        Route::put('/tailles/{taille}', [TailleController::class, 'update']);
        Route::delete('/tailles/{taille}', [TailleController::class, 'destroy']);

        Route::post('/categories-personnel', [CategoriePersonnelController::class, 'store']);
        Route::put('/categories-personnel/{categoriePersonnel}', [CategoriePersonnelController::class, 'update']);
        Route::delete('/categories-personnel/{categoriePersonnel}', [CategoriePersonnelController::class, 'destroy']);

        Route::post('/grades', [GradeController::class, 'store']);
        Route::put('/grades/{grade}', [GradeController::class, 'update']);
        Route::delete('/grades/{grade}', [GradeController::class, 'destroy']);

        Route::post('/unites', [UniteController::class, 'store']);
        Route::put('/unites/{unite}', [UniteController::class, 'update']);
        Route::delete('/unites/{unite}', [UniteController::class, 'destroy']);

        Route::post('/magasins', [MagasinController::class, 'store']);
        Route::put('/magasins/{magasin}', [MagasinController::class, 'update']);
        Route::delete('/magasins/{magasin}', [MagasinController::class, 'destroy']);
        Route::post('/magasins/{magasin}/rattacher', [MagasinController::class, 'rattacher']);
        Route::delete('/magasins/{magasin}/rattacher/{user}', [MagasinController::class, 'detacher']);

        // Comptes et journal d'audit (inchangés)
        Route::apiResource('users', UserController::class);
        Route::delete('/users/{user}/2fa', [UserController::class, 'reinitialiserDoubleFacteur']);
        Route::get('/audit', [AuditController::class, 'index']);
    });

    // ===================================================================== F2 : militaires et mensurations
    // Lecture : administrateur, magasinier, chef d'unité (limité à son unité dans le contrôleur)
    Route::middleware('role:administrateur,magasinier,chef_unite')->group(function () {
        Route::get('/militaires', [MilitaireController::class, 'index']);
        Route::get('/militaires/{militaire}', [MilitaireController::class, 'show']);
    });
    Route::post('/militaires/{militaire}/mensurations', [MensurationController::class, 'store'])->middleware('role:administrateur,magasinier');
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/militaires', [MilitaireController::class, 'store']);
        Route::put('/militaires/{militaire}', [MilitaireController::class, 'update']);
        Route::patch('/militaires/{militaire}/statut', [MilitaireController::class, 'statut']);
        Route::delete('/militaires/{militaire}', [MilitaireController::class, 'destroy']);
    });

    // ===================================================================== F3 : stock des magasins
    // Administrateur : tous les magasins ; magasinier : ses magasins (contrôlé dans le contrôleur, RG8)
    Route::middleware('role:administrateur,magasinier')->group(function () {
        Route::get('/magasins/{magasin}/stock', [StockController::class, 'etat']);
        Route::post('/magasins/{magasin}/entrees', [StockController::class, 'entree']);
        Route::post('/magasins/{magasin}/reformes', [StockController::class, 'reforme']);
        Route::patch('/magasins/{magasin}/seuils', [StockController::class, 'seuils']);
        Route::get('/mouvements-stock', [MouvementStockController::class, 'index']);

        // ================================================================= F4 / F5 : dotations, incidents (actions)
        Route::get('/dotations/proposition', [DotationController::class, 'proposition']);
        Route::post('/dotations', [DotationController::class, 'store']);
        Route::post('/dotation-lignes/{dotationLigne}/incidents', [IncidentController::class, 'store']);
        Route::post('/incidents/{incident}/remplacement', [IncidentController::class, 'remplacement']);
        Route::post('/militaires/{militaire}/reintegration', [IncidentController::class, 'reintegration']);
    });

    // Consultation des bons et des incidents : tous les rôles, filtrés dans le contrôleur
    // (chef d'unité : son unité ; militaire : les siens)
    Route::get('/dotations', [DotationController::class, 'index']);
    Route::get('/dotations/{dotation}', [DotationController::class, 'show']);
    Route::get('/incidents', [IncidentController::class, 'index']);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
    Route::get('/militaires/{militaire}/paquetage', [IndicateurController::class, 'paquetage']);

    // ===================================================================== F6 : indicateurs
    Route::get('/tableau-de-bord', [IndicateurController::class, 'tableauDeBord'])->middleware('role:administrateur,magasinier,chef_unite');
    Route::get('/previsions', [IndicateurController::class, 'previsions'])->middleware('role:administrateur,chef_unite');
    Route::get('/mon-espace', [IndicateurController::class, 'monEspace'])->middleware('role:militaire');
});
