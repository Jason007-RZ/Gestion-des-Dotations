# Refonte du backend (cahier des charges officiel) : met de côté les fichiers de l'ancien modèle (PowerShell 5.1).
# Lancer depuis la racine du backend, APRÈS avoir décompressé l'archive de la refonte :
#   .\refonte-backend.ps1             (simple liste)
#   .\refonte-backend.ps1 -Appliquer  (déplace)
# Rien n'est supprimé : tout part dans un dossier "_ancien_modele_..." à côté du projet.
param([switch]$Appliquer)

$ErrorActionPreference = 'Stop'
$racine = (Get-Location).Path

if (-not (Test-Path -LiteralPath (Join-Path $racine 'artisan'))) {
    Write-Host "Ce dossier n'est pas la racine du backend Laravel (fichier artisan introuvable)." -ForegroundColor Red
    exit 1
}
if (-not (Test-Path -LiteralPath (Join-Path $racine 'app\Support\Referentiels.php'))) {
    Write-Host "Décompressez d'abord l'archive de la refonte à la racine du projet, puis relancez." -ForegroundColor Red
    exit 1
}

$anciens = @(
    # Ancien modèle de données
    'database\migrations\2026_09_24_000007_create_mensuration_historiques_table.php',
    'database\migrations\2026_09_24_000009_create_declinaisons_table.php',
    'database\migrations\2026_09_24_000010_create_baremes_table.php',
    'database\migrations\2026_09_24_000012_create_mouvements_table.php',
    'database\migrations\2026_10_09_121129_add_statut_to_militaires_table.php',
    'database\migrations\2026_10_30_000002_create_demandes_table.php',
    'app\Models\Bareme.php', 'app\Models\Declinaison.php', 'app\Models\Demande.php',
    'app\Models\MensurationHistorique.php', 'app\Models\Mouvement.php',
    # Anciens contrôleurs sans équivalent direct (les autres sont remplacés par l'archive elle-même)
    'app\Http\Controllers\Api\BaremeController.php', 'app\Http\Controllers\Api\DeclinaisonController.php',
    'app\Http\Controllers\Api\DemandeController.php', 'app\Http\Controllers\Api\MouvementController.php',
    'app\Http\Controllers\Api\PdfController.php', 'app\Http\Controllers\Api\PrevisionController.php',
    'app\Http\Controllers\Api\StockController.php',
    'app\Support\CircuitDemande.php', 'app\Support\Detentions.php', 'app\Support\Echeances.php', 'app\Support\Paquetage.php',
    'app\Exceptions\DerogationRequise.php',
    'resources\views\pdf',
    # Anciens tests et documents
    'tests\Unit\CircuitDemandeTest.php', 'tests\Unit\DetentionsTest.php', 'tests\Unit\EcheancesTest.php', 'tests\Unit\PaquetageTest.php',
    'tests\Feature\DemandesTest.php', 'tests\Feature\PrevisionsTest.php',
    'DEMANDES.md', 'PREVISIONS.md', 'api-routes-restreint.php', 'refonte-etape1.ps1'
)

$cibles = @($anciens | Where-Object { Test-Path -LiteralPath (Join-Path $racine $_) })

if ($cibles.Count -eq 0) {
    Write-Host 'Rien à déplacer : déjà fait.' -ForegroundColor Green
    exit 0
}

Write-Host 'Fichiers de l''ancien modèle :'
$cibles | ForEach-Object { Write-Host ('  - ' + $_) }

if (-not $Appliquer) {
    Write-Host ''
    Write-Host 'Simulation : rien n''a été déplacé. Relancez avec -Appliquer.' -ForegroundColor Yellow
    exit 0
}

$dossier = Join-Path (Split-Path -Parent $racine) ('_ancien_modele_' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
foreach ($c in $cibles) {
    $destination = Join-Path $dossier $c
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
    Move-Item -LiteralPath (Join-Path $racine $c) -Destination $destination
}

# Le dossier app\Exceptions peut rester vide : on le retire s'il l'est
$exc = Join-Path $racine 'app\Exceptions'
if ((Test-Path -LiteralPath $exc) -and -not (Get-ChildItem -LiteralPath $exc -Force)) { Remove-Item -LiteralPath $exc }

Write-Host ''
Write-Host ('Déplacé vers : ' + $dossier) -ForegroundColor Green
Write-Host 'Étapes suivantes (voir REFONTE.md) : APP_LOCALE=fr dans .env, puis php artisan migrate:fresh --seed --seeder=ReferentielsSeeder'
