# Contrat d'API de la refonte (cahier des charges officiel)

Le frontend est refait **avant** le backend. Ce document fixe ce que chaque écran envoie et attend :
le backend devra le respecter exactement. Il s'enrichit à chaque étape.

Conventions (inchangées) :
- Préfixe `/api`, jeton Sanctum `Authorization: Bearer …`, JSON.
- Erreur de saisie : **422** `{ "message": "...", "errors": { "champ": ["..."] } }`.
- Refus métier (ex. suppression d'un élément utilisé) : **422** `{ "message": "..." }`.
- Rôle insuffisant : **403** `{ "message": "..." }`.
- Les dates sont au format `AAAA-MM-JJ`.

---

## Étape F1 : référentiels

### Lecture groupée : `GET /referentiels` (tous les rôles connectés)

Une seule requête alimente toutes les listes déroulantes de l'application.

```json
{
  "categories_article": [{ "id": 1, "code": "HAB", "libelle": "Habillement", "nb_articles": 4 }],
  "grilles_taille": [
    { "id": 1, "libelle": "Vêtement", "nb_articles": 3,
      "tailles": [{ "id": 1, "grille_taille_id": 1, "valeur": "S", "ordre": 1 }] }
  ],
  "categories_personnel": [
    { "id": 1, "libelle": "Recrue", "nb_lignes_tableau": 12,
      "grades": [{ "id": 1, "libelle": "Recrue", "categorie_personnel_id": 1, "ordre": 1, "nb_militaires": 0 }] }
  ],
  "unites": [{ "id": 1, "code": "CIR", "libelle": "Centre d'instruction des recrues", "nb_militaires": 0 }],
  "magasins": [{ "id": 1, "libelle": "Magasin d'habillement CIR", "unite_id": 1, "unite": { "id": 1, "code": "CIR", "libelle": "…" } }]
}
```

Tailles triées par `ordre`, grades triés par `ordre`. Les compteurs `nb_*` servent à expliquer
pourquoi une suppression est refusée ; ils sont facultatifs (absents = 0).

### Articles (catalogue)

`GET /articles` (tous les rôles) → tableau :

```json
{ "id": 1, "code": "HAB-TRE", "designation": "Treillis (veste + pantalon)",
  "categorie_id": 1, "categorie": { "id": 1, "code": "HAB", "libelle": "Habillement" },
  "grille_taille_id": 1, "grille_taille": { "id": 1, "libelle": "Vêtement", "tailles": [ … ] },
  "duree_vie_mois": 12, "a_reintegrer": false, "actif": true }
```

`grille_taille_id` = `null` : taille unique. `duree_vie_mois` = `null` : pas de renouvellement (réintégrable).

Administrateur seulement :

| Méthode | Route | Corps |
|---|---|---|
| POST | `/articles` | `code`, `designation`, `categorie_id`, `grille_taille_id` (ou null), `duree_vie_mois` (ou null), `a_reintegrer`, `actif` |
| PUT | `/articles/{id}` | mêmes champs |
| DELETE | `/articles/{id}` | — ; refusé (422) si l'article figure dans un tableau de dotation, un stock ou un bon : il faut le **désactiver** (`actif: false`) |

Règles : `code` unique (20 caractères max, majuscules conseillées), `designation` obligatoire,
`duree_vie_mois` entier de 1 à 240 ou null. Changer la grille d'un article déjà en stock ou sur un bon est refusé (422).

### Tableau de dotation

`GET /tableau-dotation?categorie_personnel_id=1` (administrateur, magasinier, chef d'unité) → tableau :

```json
{ "id": 1, "categorie_personnel_id": 1, "article_id": 1,
  "article": { "id": 1, "code": "HAB-TRE", "designation": "…", "grille_taille": { "libelle": "Vêtement" }, "a_reintegrer": false },
  "quantite_reglementaire": 2, "periodicite_mois": 12 }
```

Administrateur seulement :

| Méthode | Route | Corps |
|---|---|---|
| POST | `/tableau-dotation` | `categorie_personnel_id`, `article_id`, `quantite_reglementaire` (1 à 99), `periodicite_mois` (1 à 240, ou null = réintégrable) |
| PUT | `/tableau-dotation/{id}` | `quantite_reglementaire`, `periodicite_mois` |
| DELETE | `/tableau-dotation/{id}` | — |
| POST | `/tableau-dotation/copier` | `source_id`, `cible_id` : copie les lignes d'une catégorie vers une autre ; les articles déjà présents dans la cible ne sont pas modifiés. Réponse : `{ "copiees": 3, "ignorees": 9 }` |

Règles : un article ne figure qu'une fois par catégorie (422 sinon) ; un article inactif ne peut pas être ajouté.

### Catégories d'articles, grilles et tailles, catégories de personnel et grades, unités, magasins

Administrateur seulement. Chaque création ou modification renvoie l'objet enregistré ; chaque suppression renvoie 204.

| Ressource | Routes | Champs | Suppression refusée (422) si… |
|---|---|---|---|
| Catégorie d'article | `POST /categories-article`, `PUT/DELETE /categories-article/{id}` | `code` (unique, 10 car.), `libelle` | des articles l'utilisent |
| Grille de taille | `POST /grilles-taille`, `PUT/DELETE /grilles-taille/{id}` | `libelle` (unique) | des articles l'utilisent |
| Taille | `POST /grilles-taille/{id}/tailles`, `PUT/DELETE /tailles/{id}` | `valeur` (unique dans la grille), `ordre` | elle figure sur une mensuration, un stock ou un bon |
| Catégorie de personnel | `POST /categories-personnel`, `PUT/DELETE /categories-personnel/{id}` | `libelle` (unique) | elle a des grades ou des lignes de tableau de dotation |
| Grade | `POST /grades`, `PUT/DELETE /grades/{id}` | `libelle` (unique), `categorie_personnel_id`, `ordre` | des militaires portent ce grade |
| Unité | `POST /unites`, `PUT/DELETE /unites/{id}` | `code` (unique), `libelle` | elle a des militaires ou des magasins |
| Magasin | `POST /magasins`, `PUT/DELETE /magasins/{id}` | `libelle`, `unite_id` (ou null) | il a du stock ou des bons |

### Grilles utilisées par les mensurations

`GET /referentiels` renvoie aussi quelle grille sert à chaque mesure (le libellé d'une grille peut changer) :

```json
"grilles_mensuration": { "taille_vetement": 1, "pointure": 2, "tour_tete": 3 }
```

### Autres changements visibles

`GET /me` : `unite` porte désormais `libelle` (et non plus `nom`) ; `magasins` = `[{ id, libelle }]`.

---

## Étape F2 : militaires et mensurations

Accès : administrateur (tout), magasinier (consultation de tous les militaires et saisie des mensurations),
chef d'unité (consultation des militaires de **son** unité seulement ; 403 sur un militaire d'une autre unité).
Le rôle militaire n'a pas accès à ces routes (il voit sa fiche dans « Mon espace », étape F6).

### Forme d'un militaire

```json
{ "id": 7, "matricule": "MAT-24015", "nom": "RAKOTOARISOA", "prenoms": "Hery Tiana",
  "grade_id": 1, "grade": { "id": 1, "libelle": "Recrue", "categorie_personnel_id": 1, "categorie": { "id": 1, "libelle": "Recrue" } },
  "unite_id": 1, "unite": { "id": 1, "code": "CIR", "libelle": "Centre d'instruction des recrues" },
  "date_incorporation": "2026-09-01", "statut": "actif",
  "mensuration_actuelle": { "id": 3, "date_mesure": "2026-09-02",
    "taille_vetement": { "id": 2, "valeur": "M" }, "pointure": { "id": 9, "valeur": "41" }, "tour_tete": null } }
```

`statut` : `actif`, `mute`, `radie`. `mensuration_actuelle` : la plus récente (`null` si aucune).

### Routes

| Méthode | Route | Rôles | Détail |
|---|---|---|---|
| GET | `/militaires` | admin, magasinier, chef | Paramètres : `q` (matricule, nom, prénoms), `unite_id`, `statut`, `categorie_personnel_id`, `sans_mensuration=1`, `page`, `par_page` (≤ 100, défaut 20). Réponse paginée Laravel : `{ "data": [...], "current_page", "last_page", "per_page", "total" }`. Tri : nom, prénoms. Chef d'unité : limité à son unité quel que soit `unite_id`. |
| GET | `/militaires/{id}` | admin, magasinier, chef (son unité) | Le militaire + `"mensurations": [ ... ]` (historique, la plus récente d'abord, chaque mesure avec `saisie_par: { "id", "name" }`). |
| POST | `/militaires` | admin | `matricule` (unique, 30 car.), `nom`, `prenoms`, `grade_id`, `unite_id`, `date_incorporation` (pas dans le futur). Statut initial : `actif`. Réponse 201 : le militaire. |
| PUT | `/militaires/{id}` | admin | Mêmes champs (sans le statut). |
| PATCH | `/militaires/{id}/statut` | admin | `statut` (`actif`, `mute`, `radie`), `motif` (obligatoire pour `mute` et `radie`, 255 car.). Refusé (422) s'il détient encore des articles « à réintégrer » (règle de clôture du dossier, active à l'étape des réintégrations). |
| DELETE | `/militaires/{id}` | admin | Refusé (422) s'il a déjà reçu une dotation : il faut le radier. |
| POST | `/militaires/{id}/mensurations` | admin, magasinier | `date_mesure` (pas dans le futur, défaut aujourd'hui), `taille_vetement_id`, `pointure_id`, `tour_tete_id` (chacun facultatif mais au moins un ; chaque taille doit appartenir à la grille correspondante de `grilles_mensuration`). Refusé (422) pour un militaire radié. Réponse 201 : la mesure. |

Messages d'erreur attendus (422) : « Le matricule MAT-24015 est déjà utilisé. », « Indiquez au moins une mesure. »,
« La taille 41 n'appartient pas à la grille Vêtement. », « Impossible : ce militaire a déjà reçu une dotation. Radiez-le plutôt. »

---

## Étape F3 : stock des magasins

Accès : administrateur (tous les magasins) et magasinier (**ses** magasins seulement, 403 sinon).
Les sorties de stock ne se saisissent pas ici : elles sont créées par les bons de dotation (étape F5) ;
les réintégrations, par le retour d'un article par un militaire (étape F6).

### État du stock : `GET /magasins/{id}/stock`

Une ligne par article **actif** et par taille de sa grille (une seule ligne, `taille` nulle, pour une taille unique),
y compris les combinaisons jamais stockées (`id` nul, quantité 0). Tri : code article, puis ordre de taille.

```json
[{ "id": 12, "magasin_id": 1,
   "article": { "id": 1, "code": "HAB-TRE", "designation": "Treillis (veste + pantalon)", "categorie_id": 1 },
   "taille": { "id": 2, "valeur": "M", "ordre": 2 },
   "quantite": 14, "seuil_alerte": 5, "en_alerte": false }]
```

`en_alerte` = `quantite <= seuil_alerte` et `seuil_alerte > 0`.

### Mouvements

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | `/magasins/{id}/entrees` | `reference` (n° du bon de livraison, obligatoire, 50 car.), `date` (pas dans le futur), `lignes: [{ article_id, taille_id, quantite }]` | Un mouvement `entree` par ligne ; stock augmenté. |
| POST | `/magasins/{id}/reformes` | `reference` (n° du PV de réforme), `date`, `motif` (obligatoire), `lignes: [...]` | Un mouvement `reforme` par ligne ; stock diminué. **Tout ou rien** : si une ligne dépasse le stock, rien n'est enregistré (422). |
| PATCH | `/magasins/{id}/seuils` | `lignes: [{ article_id, taille_id, seuil_alerte }]` (0 à 10 000) | Seuils mis à jour (ligne de stock créée à 0 si besoin). |

Règles communes : 1 à 100 lignes ; `quantite` entière de 1 à 10 000 ; article actif ; `taille_id` appartenant à la grille
de l'article (null pour une taille unique) ; pas deux fois la même combinaison article + taille dans un bon.
Réponse des entrées et réformes (201) : `{ "mouvements": [...], "reference": "BL-2026-118" }`.
Le stock ne devient jamais négatif (verrou sur les lignes de stock pendant l'enregistrement).

Messages (422) attendus : « Stock insuffisant : 3 « Treillis (veste + pantalon) » taille M en stock, 5 à réformer. »,
« La taille 41 n'existe pas pour « Treillis (veste + pantalon) ». », « Ajoutez au moins une ligne. »

### Journal : `GET /mouvements-stock`

Paramètres : `magasin_id`, `type` (`entree`, `sortie`, `reintegration`, `reforme`), `article_id`, `du`, `au`, `reference`, `page`, `par_page`.
Magasinier : limité à ses magasins. Réponse paginée, la plus récente d'abord :

```json
{ "data": [{ "id": 90, "type": "entree", "quantite": 40, "date": "2026-11-03T08:15:00", "reference": "BL-2026-118",
             "magasin": { "id": 1, "libelle": "…" }, "article": { "id": 1, "code": "HAB-TRE", "designation": "…" },
             "taille": { "id": 2, "valeur": "M" }, "user": { "id": 2, "name": "…" }, "motif": null }],
  "current_page": 1, "last_page": 3, "per_page": 25, "total": 61 }
```

`motif` : présent pour une réforme (stocké dans le journal d'audit ou une colonne `observation`, au choix du backend).

---

## Étape F4 : bons de dotation

Accès : création par l'administrateur et le magasinier (depuis **ses** magasins). Consultation : administrateur et
magasinier (tous les bons), chef d'unité (bons des militaires de son unité), militaire (ses propres bons).

### Règles de gestion (cahier des charges §3)

1. **Dotation initiale proposée automatiquement** : lignes du tableau de dotation de la catégorie du militaire
   (catégorie de son grade), tailles préremplies d'après sa mensuration la plus récente (grille de l'article).
   Une seule dotation initiale par militaire.
2. **Contrôle du stock** : un bon est refusé si une taille demandée n'est pas disponible en quantité suffisante dans le
   magasin. Chaque ligne validée crée un mouvement `sortie` (référence = numéro du bon) et diminue le stock.
3. **Échéance** : `date_echeance = date du bon + periodicite_mois` (du tableau de dotation de la catégorie) ;
   vide pour un article réintégrable (pas de renouvellement).
4. **Pas de double dotation** : un article ne peut être redonné que si la ligne en service correspondante est échue
   (`date_echeance <= date du bon`) ; un article réintégrable déjà en service ne peut pas être redonné. Seul un
   **remplacement** justifié par un incident (étape F5) échappe à cette règle.
5. **Renouvellement** : chaque ligne renouvelée indique la ligne échue qu'elle remplace (`remplace_ligne_id`) ;
   l'ancienne passe à l'état `reforme` (effet usé rendu et réformé, sans retour en stock).
6. Militaire **muté ou radié** : aucun nouveau bon. Quantité ≤ quantité réglementaire de la catégorie.
   Seuls les articles du tableau de dotation de sa catégorie peuvent figurer sur un bon.

Numéro de bon attribué par le serveur : `BD-AAAA-NNNNNN` (séquence annuelle, sans trou).

### Proposition : `GET /dotations/proposition?militaire_id=&magasin_id=&type=initiale|renouvellement`

Ne modifie rien. Réponse :

```json
{ "type": "initiale", "date": "2026-11-03",
  "militaire": { …forme F2… }, "categorie": { "id": 1, "libelle": "Recrue" },
  "deja_dote": false,
  "lignes": [{
    "article": { "id": 1, "code": "HAB-TRE", "designation": "…", "a_reintegrer": false, "grille_taille": { "id": 1, "libelle": "Vêtement" } },
    "taille": { "id": 2, "valeur": "M" },
    "tailles": [{ "id": 1, "valeur": "S", "stock": 12 }, { "id": 2, "valeur": "M", "stock": 0 }],
    "quantite": 2, "quantite_reglementaire": 2, "periodicite_mois": 12, "date_echeance": "2027-11-03",
    "stock": 0, "remplace_ligne_id": null,
    "probleme": "Stock insuffisant : 0 en taille M." }],
  "avertissements": ["Aucune pointure mesurée : choisissez la taille à la main."] }
```

`probleme` (ou null) : ce qui empêcherait d'enregistrer cette ligne telle quelle (stock, taille manquante, déjà en service).
Pour `renouvellement` : une ligne par ligne en service **échue** (avec `remplace_ligne_id`), quantité identique.

### Création : `POST /dotations`

```json
{ "militaire_id": 7, "magasin_id": 1, "type": "initiale", "date": "2026-11-03", "observation": null,
  "lignes": [{ "article_id": 1, "taille_id": 2, "quantite": 2, "remplace_ligne_id": null }] }
```

Tout ou rien (transaction, verrou des lignes de stock). 201 : le bon complet (forme ci-dessous).
422 avec un message par problème, par exemple : « Stock insuffisant : 1 « Rangers (paire) » taille 42 en stock, 1 demandé(s)… »,
« « Béret » est déjà en service jusqu'au 03/11/2027 : renouvellement impossible avant l'échéance. »,
« RAKOTO Hery a déjà reçu sa dotation initiale (BD-2026-000012). », « Ce militaire est muté : plus de nouveau bon. »

### Bons : `GET /dotations`, `GET /dotations/{id}`

Liste paginée (la plus récente d'abord), paramètres : `q` (numéro de bon, matricule, nom), `type`, `magasin_id`,
`militaire_id`, `du`, `au`, `page`, `par_page`. Chaque bon :

```json
{ "id": 31, "numero_bon": "BD-2026-000031", "type": "initiale", "date": "2026-11-03", "observation": null,
  "militaire": { "id": 7, "matricule": "…", "nom": "…", "prenoms": "…", "grade": { "libelle": "Recrue" }, "unite": { "code": "CIR" } },
  "magasin": { "id": 1, "libelle": "…" }, "magasinier": { "id": 2, "name": "…" }, "incident_id": null,
  "nb_lignes": 12, "nb_pieces": 18 }
```

`GET /dotations/{id}` ajoute `"lignes": [{ "id", "article": {…}, "taille": {…}|null, "quantite", "date_echeance", "etat" }]`.

### Paquetage : `GET /militaires/{id}/paquetage`

Mêmes droits que la fiche (chef : son unité ; militaire : lui-même). Lignes à l'état `en_service` :

```json
[{ "id": 55, "dotation": { "id": 31, "numero_bon": "BD-2026-000031", "date": "2026-11-03", "type": "initiale" },
   "article": { … }, "taille": { "valeur": "M" }, "quantite": 2, "date_echeance": "2027-11-03",
   "echeance": "a_jour" }]
```

`echeance` : `echue` (date dépassée), `bientot` (dans les 30 jours), `a_jour`, `sans` (réintégrable).

### Changement de statut (complète F2)

`PATCH /militaires/{id}/statut` vers `mute` ou `radie` : refusé (422) tant qu'un article `a_reintegrer` est en service
(« Clôture impossible : 2 article(s) à réintégrer encore en service (Sac à dos, Couverture). »). La réintégration
elle-même arrive à l'étape F5.

---

## Étape F5 : incidents, remplacement, réintégration et clôture

Accès : déclaration, remplacement et réintégration par l'administrateur et le magasinier (le magasinier depuis ses
magasins pour ce qui touche au stock). Consultation : chef d'unité (son unité), militaire (ses incidents).

### Règles (cahier des charges §3)

- Un **incident** (perte ou détérioration) porte sur une ligne de bon **en service**, avec un **numéro de PV unique**
  et l'**imputabilité** (oui/non). Il peut ne concerner qu'une partie de la ligne (1 tee-shirt perdu sur 3) : la ligne
  est alors scindée, la partie concernée devient une nouvelle ligne à laquelle l'incident est rattaché.
  Effet : perte → ligne `perdu` ; détérioration → ligne `reforme` (effet rendu hors d'usage). Le stock ne change pas.
- Le **remplacement** est un bon de type `remplacement` relié à l'incident (`incident_id`) : même article, même quantité,
  taille au choix (par défaut celle de la ligne), échéance recalculée depuis la date du remplacement. Un seul
  remplacement par incident. Il échappe à la règle « pas de double dotation » ; le contrôle du stock s'applique.
- La **réintégration** rend des lignes en service au magasin : ligne `reintegre`, mouvement de stock `reintegration`
  (référence `RI-AAAA-NNNNNN`), stock augmenté. À la mutation ou à la radiation, tous les articles `a_reintegrer`
  doivent avoir été réintégrés (ou faire l'objet d'un incident) avant le changement de statut (déjà exigé en F4).

### Routes

| Méthode | Route | Corps / paramètres | Réponse |
|---|---|---|---|
| POST | `/dotation-lignes/{id}/incidents` | `type` (`perte`, `deterioration`), `date` (pas dans le futur), `pv_numero` (unique, 30 car.), `imputable` (booléen), `quantite` (1 à la quantité de la ligne, défaut : toute la ligne), `observation` | 201 : l'incident |
| GET | `/incidents` | `q` (PV, matricule, nom), `type`, `imputable` (0/1), `a_remplacer=1` (sans remplacement), `militaire_id`, `page`, `par_page` | paginé |
| GET | `/incidents/{id}` | — | l'incident |
| POST | `/incidents/{id}/remplacement` | `magasin_id`, `taille_id` (si l'article a une grille), `date`, `observation` | 201 : le bon (forme F4) |
| POST | `/militaires/{id}/reintegration` | `magasin_id`, `date`, `lignes: [id, …]` (lignes en service du militaire) | 201 : `{ "reference": "RI-2026-000004", "lignes": [...] }` |

Forme d'un incident :

```json
{ "id": 4, "type": "perte", "date": "2026-11-03", "pv_numero": "PV-2026-031", "imputable": true, "observation": "…",
  "ligne": { "id": 81, "article": { "code": "HAB-TSK", "designation": "Tee-shirt kaki", "grille_taille": {…} },
             "taille": { "id": 2, "valeur": "M" }, "quantite": 1, "etat": "perdu",
             "dotation": { "id": 12, "numero_bon": "BD-2026-000012", "date": "2026-03-04" } },
  "militaire": { "id": 7, "matricule": "…", "nom": "…", "prenoms": "…", "grade": { "libelle": "…" }, "unite": { "code": "CIR" } },
  "remplacement": { "id": 40, "numero_bon": "BD-2026-000040", "date": "2026-11-04" } }
```

`remplacement` : `null` tant qu'aucun bon de remplacement n'a été fait.

Messages (422) : « Le PV PV-2026-031 est déjà enregistré. », « Cette ligne n'est plus en service (perdu). »,
« Un remplacement existe déjà pour cet incident (BD-2026-000040). », « Stock insuffisant : … »,
« La ligne 81 n'appartient pas à ce militaire ou n'est plus en service. »

---

## Étape F6 : tableau de bord, espace du militaire, prévisions

### Tableau de bord : `GET /tableau-de-bord`

Administrateur (tout, filtre `unite_id` facultatif), magasinier (stock de **ses** magasins), chef d'unité (son unité,
sans le stock). Le militaire n'y a pas accès (il a « Mon espace »).

```json
{ "effectif": { "actifs": 37, "mutes": 2, "radies": 1 },
  "echeances": { "echues": 4, "a_30_jours": 9 },
  "renouvellements": [{ "ligne_id": 55, "date_echeance": "2026-11-20", "statut": "bientot",
                        "article": { "code": "HAB-TSK", "designation": "Tee-shirt kaki" }, "taille": { "valeur": "M" }, "quantite": 3,
                        "militaire": { "id": 7, "matricule": "…", "nom": "…", "prenoms": "…", "unite": { "code": "CIR" } } }],
  "a_mesurer": 3, "sans_dotation_initiale": 4, "incidents_a_remplacer": 1,
  "stock": { "alertes": 16, "ruptures": 7,
             "lignes": [{ "magasin": { "id": 1, "libelle": "…" }, "article": { … }, "taille": { "valeur": "S" }, "quantite": 0, "seuil_alerte": 2 }] },
  "sorties_par_semaine": [{ "semaine": "2026-10-12", "quantite": 54 }] }
```

- `renouvellements` : lignes en service **échues** ou à échéance **dans les 30 jours**, les plus urgentes d'abord (50 au plus) ;
  `statut` = `echue` ou `bientot`.
- `a_mesurer` : militaires actifs sans mensuration. `sans_dotation_initiale` : militaires actifs mesurés, dont la catégorie
  a un tableau de dotation, sans bon initial.
- `stock` : absent pour le chef d'unité ; `lignes` = les 10 lignes les plus critiques (ruptures puis alertes).
- `sorties_par_semaine` : pièces sorties par bons de dotation, 8 dernières semaines (lundi de chaque semaine).

### Espace du militaire : `GET /mon-espace`

Rôle `militaire`, compte relié à une fiche (`militaire_id`) ; 404 sinon.

```json
{ "militaire": { …forme F2, avec mensuration_actuelle… },
  "paquetage": [ …forme F4 (GET /militaires/{id}/paquetage)… ],
  "resume": { "articles": 12, "echus": 0, "a_30_jours": 2, "a_reintegrer": 3 } }
```

### Prévisions : `GET /previsions?du=&au=&unite_id=`

Administrateur et chef d'unité (limité à son unité). Période par défaut : aujourd'hui → +12 mois.
Calcul : lignes en service dont l'échéance tombe dans la période (à renouveler), plus celles déjà échues (en retard),
regroupées par article et taille, comparées au stock de tous les magasins.

```json
{ "du": "2026-11-03", "au": "2027-11-03", "unite_id": null,
  "par_mois": [{ "mois": "2026-12", "quantite": 14 }],
  "lignes": [{ "article": { "code": "HAB-TSK", "designation": "…" }, "taille": { "valeur": "M" },
               "a_renouveler": 21, "en_retard": 3, "stock": 12, "a_commander": 12 }] }
```

`a_commander = max(0, a_renouveler + en_retard − stock)`. Tri : `a_commander` décroissant.

---

## Journal d'audit (cahier §3 : « chaque opération garde l'utilisateur et la date »)

Inchangé (`GET /audit`), avec ces codes d'action, affichés par l'écran « Journal d'audit » :
`dotation_creee`, `incident_cree`, `remplacement`, `reintegration`, `statut_militaire`, `entree_stock`, `reforme`, `seuil`,
`mensuration`, `militaire_cree`, `militaire_modifie`, `militaire_supprime`, `unite_*`, `magasin_*`, `document`,
plus les actions de sécurité existantes (`connexion`, `2fa_*`, …).

## Écrans non refaits (routes inchangées)

Connexion, enrôlement, mot de passe oublié, sécurité du compte (2FA), gestion des comptes (`/users`, rattachement des
magasiniers `POST/DELETE /magasins/{id}/rattacher`), journal d'audit. « Gestion des comptes » lit désormais les unités et
magasins dans `GET /referentiels` (champs `code`, `libelle`).

## Récapitulatif pour le backend

| Étape | Routes |
|---|---|
| F1 | `GET /referentiels` ; `GET/POST /articles`, `PUT/DELETE /articles/{id}` ; `GET/POST /tableau-dotation`, `PUT/DELETE /tableau-dotation/{id}`, `POST /tableau-dotation/copier` ; `POST/PUT/DELETE` catégories d'articles, grilles et tailles, catégories de personnel, grades, unités, magasins |
| F2 | `GET/POST /militaires`, `GET/PUT/DELETE /militaires/{id}`, `PATCH /militaires/{id}/statut`, `POST /militaires/{id}/mensurations` |
| F3 | `GET /magasins/{id}/stock`, `POST /magasins/{id}/entrees`, `POST /magasins/{id}/reformes`, `PATCH /magasins/{id}/seuils`, `GET /mouvements-stock` |
| F4 | `GET /dotations/proposition`, `GET/POST /dotations`, `GET /dotations/{id}`, `GET /militaires/{id}/paquetage` |
| F5 | `POST /dotation-lignes/{id}/incidents`, `GET /incidents`, `GET /incidents/{id}`, `POST /incidents/{id}/remplacement`, `POST /militaires/{id}/reintegration` |
| F6 | `GET /tableau-de-bord`, `GET /mon-espace`, `GET /previsions` |

Le mode démonstration du frontend (API simulée) a servi à valider ces règles ; il a été retiré une fois les écrans
approuvés. Le backend doit appliquer les mêmes contrôles et renvoyer les mêmes messages.
