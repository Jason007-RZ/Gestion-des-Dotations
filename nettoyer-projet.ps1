# Nettoyage des fichiers de développement du backend (PowerShell 5.1).
# Lancer depuis la racine du projet :  .\nettoyer-projet.ps1            (simple liste)
#                                       .\nettoyer-projet.ps1 -Appliquer (déplace)
# Rien n'est supprimé : les fichiers sont DÉPLACÉS dans un dossier "_a_supprimer_..." à côté du projet.
# Vous le videz vous-même quand vous êtes sûr.
param([switch]$Appliquer)

$ErrorActionPreference = 'Stop'
$racine = (Get-Location).Path

if (-not (Test-Path -LiteralPath (Join-Path $racine 'artisan'))) {
    Write-Host "Ce dossier n'est pas la racine du backend Laravel (fichier artisan introuvable)." -ForegroundColor Red
    exit 1
}

$cibles = @()

# PDF de test à la racine
$cibles += Get-ChildItem -LiteralPath $racine -File -Filter 'bon-*.pdf' -ErrorAction SilentlyContinue

# Scripts de développement
foreach ($nom in 'liste-comptes.php', 'test-backend.ps1') {
    $f = Join-Path $racine $nom
    if (Test-Path -LiteralPath $f) { $cibles += Get-Item -LiteralPath $f }
}

# Dossier vide appD.vue (déplacé seulement s'il est vide)
$d = Join-Path $racine 'appD.vue'
if (Test-Path -LiteralPath $d -PathType Container) {
    if ((Get-ChildItem -LiteralPath $d -Force | Measure-Object).Count -eq 0) { $cibles += Get-Item -LiteralPath $d }
    else { Write-Host "appD.vue n'est pas vide : laissé en place." -ForegroundColor Yellow }
}

# Copie complète du projet logée par erreur dans routes\ (déplacée seulement si c'est bien un projet Laravel)
$copie = Join-Path $racine 'routes\dotations-militaires'
if (Test-Path -LiteralPath $copie -PathType Container) {
    if (Test-Path -LiteralPath (Join-Path $copie 'artisan')) { $cibles += Get-Item -LiteralPath $copie }
    else { Write-Host "routes\dotations-militaires ne ressemble pas à une copie du projet : laissé en place." -ForegroundColor Yellow }
}

$cibles = @($cibles | Where-Object { $_ })

if ($cibles.Count -eq 0) {
    Write-Host 'Rien à nettoyer.' -ForegroundColor Green
    exit 0
}

Write-Host 'Éléments concernés :'
$cibles | ForEach-Object { Write-Host ('  - ' + $_.FullName.Substring($racine.Length + 1)) }

if (-not $Appliquer) {
    Write-Host ''
    Write-Host 'Simulation : rien n''a été déplacé. Relancez avec -Appliquer pour les déplacer.' -ForegroundColor Yellow
    exit 0
}

$corbeille = Join-Path (Split-Path -Parent $racine) ('_a_supprimer_' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $corbeille | Out-Null

foreach ($c in $cibles) {
    Move-Item -LiteralPath $c.FullName -Destination (Join-Path $corbeille $c.Name)
}

Write-Host ''
Write-Host ('Déplacé vers : ' + $corbeille) -ForegroundColor Green
Write-Host 'Vérifiez que l''application fonctionne toujours, puis supprimez ce dossier.'
