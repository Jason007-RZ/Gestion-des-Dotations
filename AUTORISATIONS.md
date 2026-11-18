# Autorisations : qui peut faire quoi

Trois couches, toutes côté serveur (l'interface ne fait que masquer des boutons, jamais protéger) :

1. **Authentification** : jeton Sanctum (expiration + inactivité), double authentification en option.
2. **Rôle**, par route : le middleware `role:...` (`EnsureUserHasRole`) répond 403 si le rôle n'est pas listé.
3. **Objet**, par ressource : les **politiques** `App\Policies\*` répondent à « ce compte peut-il toucher CET objet ? » (unité, magasin, fiche).
   Les contrôleurs les appellent via `App\Support\Acces::militaire / unite / magasin`, qui répond 403 avec le message de la politique.
   Ailleurs : `Gate::forUser($u)->allows('view', $militaire)` ou `$u->can('view', $militaire)`.

| Politique | Capacité | Règle |
|---|---|---|
| `MilitairePolicy` | `view` | administrateur et magasinier : toutes les fiches ; chef d'unité : son unité (RG10) ; militaire : la sienne |
| `UnitePolicy` | `view` | chef d'unité : la sienne (RG10) ; les autres rôles : selon la route |
| `MagasinPolicy` | `operer` | magasinier : ses magasins (RG8) ; administrateur : tous |

Les demandes (perte, dérogation) suivent `App\Support\CircuitDemande` (créateur ≠ valideur, unité du chef, magasin de l'exécutant) ;
le contrôleur calcule `peut_decider / peut_executer / peut_annuler` côté serveur et les refait à chaque action.

## Matrice des rôles (par route)

| Fonction | Admin | Magasinier | Chef d'unité | Militaire |
|---|:-:|:-:|:-:|:-:|
| Consulter articles | ✔ | ✔ | ✔ | ✔ |
| Consulter fiches militaires | tous | tous | son unité | la sienne |
| Fiche individuelle (PDF) | ✔ | ✔ | son unité | la sienne |
| Tableau de bord | ✔ | ✔ | ✔ | — |
| Stocks, entrées, inventaire, seuils | ✔ | ses magasins | — | — |
| Perception, réintégration, échange, paquetage, annulation | ✔ | ses magasins | — | — |
| Perte directe (sans demande) | ✔ | — | — | — |
| Demande de perte / dérogation : déposer | ✔ | ✔ | — | ✔ |
| Demande : valider ou refuser | ✔ | — | son unité | — |
| Demande : enregistrer (exécuter) | ✔ | ses magasins | — | — |
| Demande : retirer | la sienne ou admin | la sienne | — | la sienne |
| PV de perte (PDF) | ✔ | ses magasins | son unité | le sien |
| Prévisions, état d'unité (PDF) | ✔ | — | son unité | — |
| Mensurations (saisie) | ✔ | ✔ | — | — |
| Unités, magasins, rattachements, barèmes, articles | ✔ | — | — | — |
| Comptes, 2FA d'un compte, journal d'audit | ✔ | — | — | — |
| Créer / modifier / supprimer un militaire | ✔ | — | — | — |

Suppression : jamais d'historique détruit (RG3). Un article ou un militaire qui a des mouvements ne peut pas être supprimé ; une erreur se corrige par un mouvement d'annulation inverse.

## Pourquoi pas Spatie Laravel-permission ?

Choix volontaire, pas un oubli :

- **Quatre rôles fixes** (administrateur, magasinier, chef d'unité, militaire), stockés dans une colonne `users.role`. Spatie sert à créer des rôles et des permissions *modifiables par l'administrateur*, besoin que le projet n'a pas.
- **Les règles importantes dépendent de l'objet**, pas seulement du rôle : « le chef d'unité de CETTE unité », « le magasinier rattaché à CE magasin », « le militaire propriétaire de CETTE fiche ». Spatie ne les exprime pas : il faudrait de toute façon des politiques, exactement celles qui existent ici.
- **Moins de surface d'attaque et de maintenance** : pas de dépendance ni de tables en plus (`roles`, `permissions`, pivots), pas de cache de permissions à invalider.
- **Chemin de migration ouvert** : si le ministère demande un jour des rôles configurables, on ajoute `spatie/laravel-permission` ; les politiques et `Acces` restent, on remplace seulement `EnsureUserHasRole` par `permission:...`.

## Mise en service

- Fichiers de développement : `.\nettoyer-projet.ps1` (liste), puis `.\nettoyer-projet.ps1 -Appliquer` (déplace `bon-*.pdf`, `liste-comptes.php`, `test-backend.ps1`, `appD.vue` s'il est vide, et la copie du projet logée dans `routes\`). Rien n'est supprimé : tout va dans un dossier `_a_supprimer_...` à côté du projet.
- Données de test : `php artisan dotations:nettoyer-tests` (liste seulement), puis `php artisan dotations:nettoyer-tests --appliquer`.
  Ne supprime que ce qui contient le **mot** « test » (« Bonnet F2 Test » oui, « Testard » non) **et** n'a aucun mouvement ni demande. Le reste est marqué « À CONSERVER ». Option `--motif=autre-mot` pour un autre mot.
- Tests : `php artisan test --filter=PoliciesTest`, puis `php artisan test` en entier.
