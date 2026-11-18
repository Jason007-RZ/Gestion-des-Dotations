# Test complet du backend "Gestion des dotations militaires"
# Lancer depuis C:\laragon\www\dotations-militaires avec : .\test-backend.ps1
# Le serveur (php artisan serve) doit tourner dans un autre terminal.

$ErrorActionPreference = "Stop"
$base = "http://127.0.0.1:8000/api"
$resultats = @()

function Test-Etape {
    param([string]$Nom, [scriptblock]$Action)
    try {
        $resultat = & $Action
        $script:resultats += [PSCustomObject]@{ Etape = $Nom; Statut = "OK"; Detail = ($resultat | Out-String).Trim() }
        Write-Host "[OK] $Nom" -ForegroundColor Green
        return $resultat
    } catch {
        $msg = $_.ErrorDetails.Message
        if (-not $msg) { $msg = $_.Exception.Message }
        $script:resultats += [PSCustomObject]@{ Etape = $Nom; Statut = "ECHEC"; Detail = $msg }
        Write-Host "[ECHEC] $Nom -> $msg" -ForegroundColor Red
        return $null
    }
}

Write-Host "`n=== 1. Authentification ===" -ForegroundColor Cyan

$bodyAdmin = @{ email = "admin@dotations.mil"; password = "password" } | ConvertTo-Json
$tokenAdmin = (Test-Etape "Login admin" { Invoke-RestMethod -Uri "$base/login" -Method Post -Body $bodyAdmin -ContentType "application/json" }).token
$headersAdmin = @{ Authorization = "Bearer $tokenAdmin"; Accept = "application/json" }

$bodyChef = @{ email = "chef0@dotations.mil"; password = "password" } | ConvertTo-Json
$tokenChef = (Test-Etape "Login chef unite" { Invoke-RestMethod -Uri "$base/login" -Method Post -Body $bodyChef -ContentType "application/json" }).token
$headersChef = @{ Authorization = "Bearer $tokenChef"; Accept = "application/json" }

$bodyMag = @{ email = "magasinier0@dotations.mil"; password = "password" } | ConvertTo-Json
$tokenMag = (Test-Etape "Login magasinier" { Invoke-RestMethod -Uri "$base/login" -Method Post -Body $bodyMag -ContentType "application/json" }).token
$headersMag = @{ Authorization = "Bearer $tokenMag"; Accept = "application/json" }

Test-Etape "Blocage sans token (doit echouer en 401)" {
    try { Invoke-RestMethod -Uri "$base/me"; throw "N'a PAS ete bloque !" }
    catch { if ($_.Exception.Response.StatusCode.value__ -eq 401) { "Bloque correctement (401)" } else { throw } }
}

Write-Host "`n=== 2. RG10 - Filtrage par unite ===" -ForegroundColor Cyan

$vueAdmin = Test-Etape "Liste militaires (admin)" { Invoke-RestMethod -Uri "$base/militaires" -Headers $headersAdmin }
$vueChef = Test-Etape "Liste militaires (chef unite)" { Invoke-RestMethod -Uri "$base/militaires" -Headers $headersChef }
if ($vueAdmin -and $vueChef) {
    Write-Host ("  Admin voit " + $vueAdmin.total + " militaires, chef unite en voit " + $vueChef.total) -ForegroundColor Yellow
}

Write-Host "`n=== 3. Referentiels (CRUD admin) ===" -ForegroundColor Cyan

$corpsUnite = @{ nom = "Unite de test $(Get-Random)"; code = "TEST-$(Get-Random -Maximum 9999)" } | ConvertTo-Json
$unite = Test-Etape "Creer une unite" { Invoke-RestMethod -Uri "$base/unites" -Method Post -Body $corpsUnite -ContentType "application/json" -Headers $headersAdmin }

Test-Etape "Lister les unites" { Invoke-RestMethod -Uri "$base/unites" -Headers $headersAdmin }

$corpsArticle = @{ designation = "Bonnet F2 Test"; categorie = "habillement"; duree_vie_mois = 24; tailles = @("Unique") } | ConvertTo-Json
$article = Test-Etape "Creer un article avec declinaison" { Invoke-RestMethod -Uri "$base/articles" -Method Post -Body $corpsArticle -ContentType "application/json" -Headers $headersAdmin }

if ($article) {
    $corpsBareme = @{ article_id = $article.id; grade = "Soldat"; quantite_due = 1; periodicite_mois = 24 } | ConvertTo-Json
    Test-Etape "Creer un bareme pour ce nouvel article" { Invoke-RestMethod -Uri "$base/baremes" -Method Post -Body $corpsBareme -ContentType "application/json" -Headers $headersAdmin }
}

Test-Etape "Lister les utilisateurs" { Invoke-RestMethod -Uri "$base/users" -Headers $headersAdmin }

Write-Host "`n=== 4. Mouvements ===" -ForegroundColor Cyan

$declinaisonTest = 5  # ajuste si besoin selon ton seed
$perception = @{ militaire_id = 2; declinaison_id = $declinaisonTest; magasin_id = 1; quantite = 1 } | ConvertTo-Json
$mvtPerception = Test-Etape "Enregistrer une perception" { Invoke-RestMethod -Uri "$base/mouvements/perception" -Method Post -Body $perception -ContentType "application/json" -Headers $headersMag }

if ($mvtPerception) {
    Test-Etape "Ecarts du militaire concerne" { Invoke-RestMethod -Uri "$base/militaires/2/ecarts" -Headers $headersAdmin }

    Test-Etape "Telecharger le bon de perception (PDF)" {
        Invoke-WebRequest -Uri "$base/mouvements/$($mvtPerception.id)/bon-perception" -Headers $headersMag -OutFile "bon-test-$($mvtPerception.id).pdf"
        "Fichier bon-test-$($mvtPerception.id).pdf genere"
    }

    Test-Etape "Annuler ce mouvement (RG3)" { Invoke-RestMethod -Uri "$base/mouvements/$($mvtPerception.id)/annuler" -Method Post -Body (@{ motif = "Test d'annulation" } | ConvertTo-Json) -ContentType "application/json" -Headers $headersMag }
}

$refus = Test-Etape "Perception hors bareme avec quantite excessive (doit echouer en 422)" {
    try {
        Invoke-RestMethod -Uri "$base/mouvements/perception" -Method Post -Body (@{ militaire_id = 2; declinaison_id = $declinaisonTest; magasin_id = 1; quantite = 99 } | ConvertTo-Json) -ContentType "application/json" -Headers $headersMag
        throw "N'a PAS ete refuse !"
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -eq 422) { "Refuse correctement (RG1)" } else { throw }
    }
}

Write-Host "`n=== 5. Stock ===" -ForegroundColor Cyan

Test-Etape "Consulter le stock du magasin 1" { Invoke-RestMethod -Uri "$base/magasins/1/stocks" -Headers $headersMag }

$entree = @{ declinaison_id = $declinaisonTest; magasin_id = 1; quantite = 10 } | ConvertTo-Json
Test-Etape "Entree de stock manuelle" { Invoke-RestMethod -Uri "$base/stocks/entree" -Method Post -Body $entree -ContentType "application/json" -Headers $headersMag }

Write-Host "`n=== 6. Prevision des besoins ===" -ForegroundColor Cyan

Test-Etape "Echeancier des renouvellements (60 mois)" { Invoke-RestMethod -Uri "$base/previsions/echeancier?mois=60" -Headers $headersAdmin }
Test-Etape "Besoins consolides (60 mois)" { Invoke-RestMethod -Uri "$base/previsions/besoins?mois=60" -Headers $headersAdmin }

Write-Host "`n=== RECAPITULATIF ===" -ForegroundColor Cyan
$resultats | Format-Table Etape, Statut -AutoSize
$echecs = $resultats | Where-Object { $_.Statut -eq "ECHEC" }
if ($echecs.Count -eq 0) {
    Write-Host "`nTous les tests sont passes." -ForegroundColor Green
} else {
    Write-Host ("`n" + $echecs.Count + " test(s) en echec - detail :") -ForegroundColor Red
    $echecs | ForEach-Object { Write-Host ("- " + $_.Etape + " : " + $_.Detail) -ForegroundColor Red }
}
