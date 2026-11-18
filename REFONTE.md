# Refonte selon le cahier des charges officiel

Le cahier des charges officiel (habillement et équipement individuel, sans armement ni munitions) impose un autre modèle
de données que celui de la première version : tableau de dotation par **catégorie de personnel**, **grades** rattachés
à ces catégories, **grilles de taille**, **bons de dotation** à plusieurs lignes, **incidents** (perte, détérioration),
**réforme**, **clôture du dossier** à la mutation ou à la radiation. La refonte se fait étape par étape.

Le frontend a été refait en premier (étapes F1 à F6, validées) ; le backend suit le contrat `API-REFONTE.md`
(dans le dossier du frontend), étape par étape.

| Étape | Contenu | État |
|---|---|---|
| 1 | Base de données, modèles, référentiels et données de démonstration | livrée |
| B1 | Référentiels : catalogue, tableau de dotation, grades, grilles et tailles, catégories, unités, magasins | livrée |
| B2 | Militaires et mensurations (+ `mensurations.user_id` : auteur de la mesure) | livrée |
| B3 | Stock : état, entrées, réformes, seuils, journal (+ `mouvements_stock.motif`) | livrée |
| B4 | Bons de dotation : proposition automatique, contrôles, échéances, numérotation, paquetage | livrée |
| B5 | Incidents (PV, partiels), remplacement, réintégration, clôture | livrée |
| B6 | Tableau de bord, espace du militaire, prévisions | livrée |

**Version finale** : toutes les routes du contrat `API-REFONTE.md` (copie à la racine du backend) sont en place.

### Organisation du code

- `app/Services/LignesBon.php` : contrôle des lignes d'un bon (article actif, taille de sa grille, quantité, doublons).
- `app/Services/StockService.php` : état du stock, entrées, réformes, seuils (verrous, tout ou rien, jamais négatif).
- `app/Services/DotationService.php` : proposition, bons (numérotation `BD-AAAA-NNNNNN`), paquetage, incidents,
  remplacement, réintégration (`RI-AAAA-NNNNNN`) — toutes les règles du cahier §3.
- `app/Services/IndicateurService.php` : tableau de bord, espace du militaire, prévisions.
- `app/Support/Personnel.php`, `Referentiels.php`, `Usages.php` : liste des militaires, clôture, référentiels, suppressions protégées.
- `app/Exceptions/RegleMetier.php` : refus d'une règle de gestion, rendu en 422 `{ "message": … }`.
- Contrôleurs minces dans `app/Http/Controllers/Api`, droits par rôle dans `routes/api.php` et `App\Support\Acces`.

## Installation (directement dans le projet, données actuelles effacées)

PowerShell, à la racine du backend, une commande à la fois :

1. Sauvegarde : `Select-String -Path .env -Pattern "DB_DATABASE"`, puis `mysqldump -u root NOM_DE_LA_BASE > sauvegarde-avant-refonte.sql`
2. Décompresser `backend-final.zip` à la racine (remplacer les fichiers existants).
3. `.\refonte-backend.ps1` (liste), puis `.\refonte-backend.ps1 -Appliquer` (range l'ancien code dans un dossier à part).
4. Dans `.env` : `APP_LOCALE=fr` (messages d'erreur en français).
5. `php artisan optimize:clear`
6. `php artisan migrate:fresh --seed --seeder=ReferentielsSeeder` : **efface toute la base**, puis crée seulement les
   référentiels du cahier (catégories, grilles, grades, 12 articles, tableau de la recrue). Pour avoir aussi des données
   de démonstration fictives (4 unités, 70 militaires, ~270 bons, stock, incidents ; comptes `@demo.mg`, mot de passe
   `demo2026`) : `php artisan migrate:fresh --seed`, ou sans rien effacer : `php artisan db:seed --class=DemoSeeder`.
   La démonstration est refusée en production et peut être relancée sans créer de doublons.
7. `php artisan securite:creer-admin votre.adresse@exemple.mg --name="Administrateur"` (si vous n'avez pas pris la démonstration).
8. `php artisan test`

Côté frontend : retirer la ligne `VITE_DEMO=1` de `.env.local`, supprimer le dossier `src\mock`, relancer `npm.cmd run dev`.

En production : `php artisan migrate`, `php artisan db:seed --class=ReferentielsSeeder`, puis `php artisan securite:creer-admin`.

## Modèle de données (conforme au cahier)

| Table | Champs |
|---|---|
| `categories_article` | code, libelle (HAB, COI, CHA, PEQ, CAMP, COU) |
| `grilles_taille` | libelle (Vêtement, Pointure, Tour de tête) |
| `tailles` | grille_taille_id, valeur, ordre |
| `articles` | code, designation, categorie_id, grille_taille_id (vide = taille unique), duree_vie_mois, a_reintegrer, actif |
| `categories_personnel` | libelle (Recrue, Militaire du rang, Sous-officier, Officier) |
| `grades` | libelle, categorie_personnel_id, ordre |
| `unites` | code, libelle |
| `magasins` | libelle, unite_id |
| `tableau_dotation` | categorie_personnel_id, article_id, quantite_reglementaire, periodicite_mois (vide = réintégrable) |
| `militaires` | matricule, nom, prenoms, grade_id, unite_id, date_incorporation, statut (actif, mute, radie) |
| `mensurations` | militaire_id, taille_vetement_id, pointure_id, tour_tete_id, date_mesure |
| `stocks` | magasin_id, article_id, taille_id, quantite, seuil_alerte |
| `mouvements_stock` | magasin_id, article_id, taille_id, type (entree, sortie, reintegration, reforme), quantite, date, reference, user_id |
| `dotations` | numero_bon, militaire_id, magasin_id, type (initiale, renouvellement, remplacement), date, magasinier_id, observation |
| `dotation_lignes` | dotation_id, article_id, taille_id, quantite, date_echeance, etat (en_service, reintegre, perdu, reforme) |
| `incidents` | dotation_ligne_id, type (perte, deterioration), date, pv_numero, imputable, observation |

Conservés sans changement : `users` (rôle, unité, fiche militaire, double authentification), `magasin_user`
(rattachement des magasiniers), `personal_access_tokens`, `audit_logs` (journal d'audit : utilisateur et date de chaque opération).

### Écarts assumés par rapport au texte du cahier

- `dotations.incident_id` (vide sauf pour un remplacement) : relie le bon de remplacement à l'incident qui le justifie
  (« remplacement justifié par un incident »). Sans ce lien, la règle ne serait pas vérifiable.
- `tailles.ordre` et `grades.ordre` : pour afficher S, M, L, XL dans l'ordre (et non alphabétique) et les grades par rang.
- Statuts sans accent en base (`mute`, `radie`) ; l'interface affiche « muté », « radié ».
- `stocks` : l'unicité magasin + article + taille est assurée par l'application (MySQL accepte plusieurs valeurs vides
  dans un index unique, cas des articles à taille unique).
- Suppressions bloquées par la base dès qu'un historique existe (grade utilisé, ligne de bon avec incident, militaire doté…).

### Données d'exemple

Le tableau de dotation de la recrue reprend l'**exemple** du cahier (12 articles, dont sac à dos, gourde et couverture
réintégrables). Les quantités et périodicités sont à remplacer par le tableau réglementaire officiel.
La liste des grades est une proposition, modifiable par l'administrateur.

## Vérifié

- Les migrations, les seeders et toute la logique métier (services) ont été **exécutés** sur SQLite avec le moteur de base
  de données de Laravel : scénario complet de 35 vérifications (stock, dotation initiale, renouvellement, double dotation,
  stock insuffisant sans rien enregistrer, incident partiel, remplacement unique, réintégration, clôture, indicateurs).
- Tous les fichiers PHP passent `php -l` ; aucune référence à l'ancien modèle ne subsiste.
- **Non exécutés ici** (PHP 8.4 requis par Laravel 13, indisponible dans mon environnement) : la couche HTTP et les tests
  PHPUnit `ReferentielsApiTest`, `MilitairesApiTest`, `DotationsApiTest`, `RefonteModeleTest`, `PoliciesTest`,
  `SecuriteTest`. C'est `php artisan test` chez vous qui fait foi : envoyez-moi toute ligne `FAIL`.
