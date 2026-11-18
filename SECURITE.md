# Sécurité de l'application — étape 5

Référentiels suivis : **OWASP Top 10:2025**, **OWASP API Security Top 10**, **NIST SP 800-63B-4** (mots de passe et authentification), **RFC 6238** (codes à usage unique TOTP).

Ce document dit ce qui a été fait, comment le vérifier, ce qu'il faut faire avant une mise en production, et — franchement — ce qui n'a pas pu être vérifié.

---

## 1. Vue d'ensemble (OWASP Top 10:2025)

| Catégorie | Ce qui a été fait |
|---|---|
| **A01 Contrôle d'accès défaillant** | Trois niveaux : authentification (Sanctum), rôle (`role:`), **objet** (`App\Support\Acces` : RG8 magasins, RG10 unités, fiche personnelle du militaire). La dérogation au barème (RG1) est réservée à l'administrateur. Refus tracés (`acces_refuse`). Le dernier administrateur ne peut ni se supprimer ni se rétrograder. |
| **A02 Mauvaise configuration** | En-têtes HTTP de sécurité, CSP, CORS en liste blanche (jamais `*`), `php artisan securite:verifier`, seeders de démonstration interdits en production, modèle `.env.production.example`. |
| **A03 Chaîne d'approvisionnement** | `npm audit` : 0 vulnérabilité (frontend et outillage Vite du backend) à la livraison. Versions verrouillées (`package-lock.json`, `composer.lock`). Aucun script chargé depuis un CDN. `composer audit` à lancer chez vous (voir §6). |
| **A04 Cryptographie** | Mots de passe en bcrypt (coût 12, mise à niveau automatique). Secrets 2FA chiffrés en base (cast `encrypted`, clé `APP_KEY`). Codes de secours stockés hachés (HMAC). Jetons Sanctum stockés hachés (SHA-256). HTTPS + HSTS en production. |
| **A05 Injection** | Eloquent (requêtes préparées) partout ; les deux seules expressions SQL brutes du projet sont des constantes (aucune donnée utilisateur). Validation stricte (types, bornes). Frontend : aucun `v-html`, `innerHTML` ni `eval`. |
| **A06 Conception non sécurisée** | Limitation de débit par route, blocage anti-force-brute, bornes sur les quantités, traçabilité (RG2, RG3), suppression refusée pour un compte ayant des mouvements. |
| **A07 Défaillances d'authentification** | Politique NIST 800-63B-4, 2FA TOTP optionnelle, jetons à durée limitée, expiration par inactivité, messages identiques pour « compte inconnu » et « mauvais mot de passe », parcours « mot de passe oublié » complet et sans fuite d'information. |
| **A08 Intégrité des logiciels et des données** | CSP `script-src 'self'` (le navigateur refuse tout script étranger), dépendances verrouillées, pas de désérialisation de données utilisateur. |
| **A09 Journalisation et alertes** | Journal d'audit enrichi (connexions, échecs, blocages, accès refusés, 2FA, comptes). Un blocage n'écrit qu'une ligne (une attaque ne peut pas saturer le journal). *Pas d'alerte automatique : voir §10.* |
| **A10 Mauvaise gestion des exceptions** | Erreurs JSON génériques (pas de trace avec `APP_DEBUG=false`), une panne du journal ou de l'envoi d'e-mail ne bloque ni ne trahit rien, le frontend n'écrit jamais l'objet d'erreur complet dans la console (il contient le jeton et le mot de passe saisi), lecture défensive du stockage navigateur. |

**OWASP API Security :** API1 (BOLA) → `Acces` ; API2 (authentification) → §2 ; API3 (propriétés d'objet) → `$fillable`, `$hidden`, jamais de `request()->all()` ; API4 (consommation de ressources) → limiteurs + bornes ; API5 (BFLA) → `role:` sur chaque groupe de routes ; API6 (flux métier sensibles) → enrôlement limité par IP et par matricule.

---

## 2. Authentification

**Mot de passe** (`config/securite.php` → `mdp_min`, 15 par défaut)
- 15 caractères minimum, 128 maximum, **aucune règle de composition** (majuscule, chiffre, symbole) : le NIST a abandonné ces règles, la longueur compte plus. Une phrase de passe convient très bien.
- Liste de blocage : mots courants (français et anglais), termes du service (« dotation », « madagascar »...), nom et e-mail du compte, suites (`abcdef`, `123456`), répétitions, variantes « leet » (`P@ssw0rd`).
- Pas de rotation forcée (déconseillée par le NIST). Copier-coller et gestionnaires de mots de passe autorisés.
- Option : refuser les mots de passe présents dans des fuites publiques (Have I Been Pwned, anonymisé par k-anonymat) → `SECURITE_VERIFIER_FUITES=true` (nécessite Internet depuis le serveur).

**Blocage anti-force-brute** (réponse 429 avec `Retry-After`) : 5 échecs / 15 min pour un même compte depuis une même IP, 15 pour un même compte (toutes IP), 40 pour une même IP (tous comptes). Un succès ne remet à zéro que les deux premiers compteurs. Le temps de réponse est identique que le compte existe ou non (faux hachage comparé).

**Jetons d'accès** : durée maximale 8 h ; refusés par le serveur après 30 min d'inactivité (le frontend déconnecte déjà à 15 min, avec avertissement de 60 s) ; 5 appareils connectés au maximum par compte ; tous révoqués lors d'un changement de rôle, d'un changement de mot de passe (sauf la session en cours), d'une réinitialisation ou d'un retrait du 2FA. Purge quotidienne des jetons expirés (voir §7).

**Double authentification (TOTP)** — optionnelle, par compte (avatar → « Sécurité du compte »). Compatible Google Authenticator, Microsoft Authenticator, Aegis, FreeOTP.
- Activation : mot de passe + scan du QR + un premier code valide. 8 codes de secours à usage unique sont remis (à télécharger).
- Connexion en deux étapes : le mot de passe seul ne délivre **aucun jeton** ; il délivre un défi valable 5 min, limité à 5 essais.
- Un code déjà utilisé est refusé (anti-rejeu). Désactiver ou régénérer les codes exige le mot de passe **et** un code.
- Téléphone et codes perdus : l'administrateur retire le 2FA depuis « Gestion des comptes », ou en ligne de commande (`securite:desactiver-2fa`).
- **Ne perdez jamais `APP_KEY`** et ne la changez pas une fois le 2FA utilisé : les secrets 2FA sont chiffrés avec elle.

**Enrôlement** (un militaire crée son compte) : toutes les causes de refus donnent le même message ; 5 essais par matricule et par heure, 5/min et 20/h par IP ; refus tracés.

**Mot de passe oublié** : réponse identique que l'adresse existe ou non, même si l'envoi du courriel échoue ; le lien du courriel mène à l'écran « Nouveau mot de passe » du frontend (`FRONTEND_URL`), avec le jeton placé dans le **fragment** de l'adresse (`#`), qui n'est jamais envoyé au serveur ni dans l'en-tête `Referer`, et que l'application efface aussitôt de la barre d'adresse. Le jeton est à usage unique, valable 60 minutes ; la réinitialisation ferme toutes les sessions du compte.

---

## 3. Contrôle d'accès

| Règle | Où elle est appliquée |
|---|---|
| Rôle autorisé pour la route | middleware `role:` dans `routes/api.php` |
| RG10 : un chef d'unité ne voit que son unité ; un militaire, que sa fiche | `Acces::militaire`, `Acces::unite` |
| RG8 : un magasinier n'opère que sur ses magasins (stock, seuils, mouvements, bons) | `Acces::magasin` |
| RG1 : dépassement du barème = dérogation **administrateur** uniquement | `MouvementController` |
| Jamais sans administrateur | `UserController` (suppression, rétrogradation) |
| RG2/RG3 : un compte ayant enregistré des mouvements ne se supprime pas | `UserController::destroy` |

**Failles trouvées et corrigées pendant l'audit :**
- Tout magasinier pouvait s'accorder une dérogation au barème → réservée à l'administrateur.
- L'historique de mensurations d'un militaire était lisible par n'importe quel compte connecté → contrôle par objet.
- Le changement de seuil et la génération du bon de perception ne vérifiaient pas le rattachement au magasin (RG8).
- Les jetons n'expiraient jamais → durée limitée, inactivité, révocation.
- La réinitialisation de mot de passe révélait l'existence d'un compte (messages différents), et le lien du courriel ne menait à aucun écran.
- `MensurationController` : les champs validés ne correspondaient pas aux colonnes de la table (probable cause d'erreurs 500 à l'enregistrement d'une mensuration).
- Supprimer un compte ayant des mouvements provoquait une erreur de base de données (500) → refus explicite (422).
- Les seeders créaient des comptes au mot de passe connu sans garde-fou → refusés en production.

---

## 4. En-têtes HTTP, CSP, CORS

**API** (`app/Http/Middleware/SecurityHeaders.php`, sur toutes les réponses) : `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Permissions-Policy` (caméra, micro, géolocalisation... coupés), `Cross-Origin-Opener-Policy` / `Cross-Origin-Resource-Policy: same-origin`, `Strict-Transport-Security` (en HTTPS), `Cache-Control: no-store` sur `/api/*` (données personnelles jamais mises en cache), CSP `default-src 'none'` sur les réponses JSON.

**Frontend** (`vite.config.ts`, ajoutée au `npm run build` uniquement) : CSP en balise `<meta>` — scripts et connexions autorisés vers l'application elle-même et l'adresse de l'API (`VITE_API_URL`) seulement. Une balise `<meta>` ne sait pas exprimer `frame-ancestors` (anti-clickjacking) : en production, ajoutez-le côté serveur web (voir §8).

**CORS** (`config/cors.php`) : liste blanche lue dans `CORS_ALLOWED_ORIGINS`. En `APP_ENV=local`, les adresses du réseau local (`localhost`, `192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`) sont acceptées en plus, pour tester depuis un téléphone.

---

## 5. Journal d'audit

Écran **Journal d'audit** (administrateur) : qui, quoi, quand, depuis quelle IP, filtrable.
Ajouts de cette étape : `connexion`, `connexion_echec`, `connexion_bloquee`, `acces_refuse`, `enrolement`, `enrolement_refuse`, `mot_de_passe_change`, `mot_de_passe_reinitialise`, `sessions_revoquees`, `2fa_active`, `2fa_desactive`, `2fa_reinitialise`, `2fa_codes_regeneres`, `code_secours_utilise`, `utilisateur_*`.

Le journal est en écriture seule pour l'application (aucun code ne modifie ni ne supprime une ligne). Pour qu'il le soit aussi pour un intrus qui aurait récupéré les identifiants de la base, créez un compte MySQL dédié **sans** `UPDATE` ni `DELETE` sur `audit_logs` (à tester chez vous, notamment avec la clé étrangère `user_id`).

---

## 6. Dépendances

| Quoi | Commande | État à la livraison |
|---|---|---|
| Frontend | `npm audit` | 0 vulnérabilité |
| Outillage Vite du backend | `npm audit` (dans le dossier Laravel) | 0 vulnérabilité |
| **Backend PHP** | `composer audit` | **non vérifié** (accès bloqué depuis mon environnement). À lancer chez vous. |

Versions installées chez vous : Laravel 13.33.0, Sanctum 4.3.3, laravel-dompdf 3.1.2. À refaire régulièrement : `composer audit`, `composer update` (puis `php artisan test`), `npm audit`. En production : `composer install --no-dev --optimize-autoloader` et `npm ci`.

---

## 7. Commandes

```text
php artisan securite:verifier                 # contrôle de configuration (OK / ATTENTION / ECHEC)
php artisan securite:verifier --purger-jetons # + supprime les anciens jetons sans expiration (reconnexion nécessaire)
php artisan securite:creer-admin email@exemple.mg --name="Nom"   # premier administrateur (mot de passe saisi masqué)
php artisan securite:desactiver-2fa email@exemple.mg             # secours : téléphone et codes perdus
php artisan sanctum:prune-expired --hours=24  # purge des jetons expirés (planifiée chaque jour)
```
Le planificateur doit tourner pour la purge automatique : `php artisan schedule:work` (développement) ou une tâche planifiée `php artisan schedule:run` chaque minute (production). Sans lui rien n'est dangereux : un jeton expiré est de toute façon refusé.

## 8. Variables `.env`

| Variable | Défaut | Rôle |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | adresses locales | Adresse(s) exacte(s) du frontend, séparées par des virgules |
| `FRONTEND_URL` | `http://localhost:5173` | Lien du courriel « mot de passe oublié » |
| `SECURITE_JETON_DUREE` | 480 | Durée maximale d'une session (minutes) |
| `SECURITE_INACTIVITE` | 30 | Inactivité tolérée par le serveur (minutes) |
| `SECURITE_MDP_MIN` | 15 | Longueur minimale (alignée dans `src/services/securite.js` → `MDP_MIN`) |
| `SECURITE_VERIFIER_FUITES` | false | Refuser les mots de passe connus des fuites (Internet requis) |
| `SECURITE_LIMITE_API` / `_PDF` | 120 / 20 | Requêtes par minute et par utilisateur |
| `SANCTUM_TOKEN_PREFIX` | vide | Préfixe des jetons (détection de fuites par les outils de scan de secrets) |

## 9. Liste de contrôle avant mise en production

- [ ] HTTPS partout (certificat valide), `APP_URL` et `FRONTEND_URL` en `https://`
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` (partir de `.env.production.example`)
- [ ] `php artisan key:generate` (clé propre à la production), `.env` hors du dépôt et hors de toute archive
- [ ] Compte MySQL dédié (pas `root`), mot de passe fort, droits limités
- [ ] `php artisan migrate --force`, puis `securite:creer-admin` (jamais `db:seed` : refusé en production)
- [ ] `CORS_ALLOWED_ORIGINS` = adresse exacte du frontend
- [ ] `php artisan securite:verifier` sans aucun ECHEC ni ATTENTION
- [ ] Activer le 2FA sur **tous** les comptes administrateur ; garder au moins deux administrateurs
- [ ] `composer audit` et `npm audit` propres ; `php artisan test` vert
- [ ] Sauvegardes automatiques de la base, restauration testée
- [ ] Derrière un proxy inverse : déclarer ses adresses (`->trustProxies(at: [...])` dans `bootstrap/app.php`), sinon toutes les IP du journal et du blocage seront celle du proxy
- [ ] Planificateur actif (purge des jetons)
- [ ] Le serveur n'expose que `public/` ; ni `.env`, ni `storage/`, ni `vendor/` accessibles par le web

**En-têtes côté serveur web (frontend)** — exemple Nginx, **non testé**, à adapter :
```nginx
server {
    listen 443 ssl;
    server_name dotations.exemple.mg;
    server_tokens off;
    root /var/www/dotations-frontend/dist;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://api.dotations.exemple.mg; object-src 'self' blob:; frame-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;

    location / { try_files $uri $uri/ /index.html; }
    location /assets/ { expires 1y; }   # pas d'add_header ici : il écraserait ceux du serveur
}
```

## 10. Nettoyage du projet (avant tout partage ou déploiement)

- **Ne jamais envoyer le `.env`** (ni `vendor/`, ni `node_modules/`) quand vous zippez le projet : il contient la clé d'application, le mot de passe de la base et celui du compte de messagerie. Si un `.env` a déjà quitté votre machine, considérez ses secrets comme exposés : nouveau mot de passe d'application Gmail, nouvelle `APP_KEY` (à faire avant que quelqu'un active le 2FA).
- Supprimer `routes/dotations-militaires/` : dans l'archive du backend que j'ai reçue, c'est une **copie complète du projet** (51 Mo, avec un second `.env` et `node_modules`) qui n'a rien à faire dans `routes/`. Ne pas déployer non plus les fichiers de développement de la racine : `bon-*.pdf` (PDF de test), `liste-comptes.php`, `test-backend.ps1`, le dossier vide `appD.vue/`.
- Supprimer les données de test (ex. « Bonnet F2 Test ») de la base avant la mise en service.

## 11. Ce qui n'a PAS été vérifié

Mon environnement ne peut pas démarrer ce Laravel (dépendances exigeant PHP 8.4, accès MySQL et Packagist absents). Donc :
- Le code PHP a été **contrôlé syntaxiquement** (`php -l`) et sa logique pure testée à part (codes TOTP contre les vecteurs de test de la RFC 6238, liste de blocage des mots de passe, codes de secours, détentions, paquetage) — **mais `tests/Feature/SecuriteTest.php` n'a jamais été exécuté**. Lancez `php artisan test` et signalez-moi tout échec : c'est la vérification qui compte.
- `composer audit` : non lancé (voir §6).
- La CSP du frontend a été vérifiée avec un navigateur (aucune violation) sur des réponses simulées de l'API, pas sur le vrai serveur ; l'ouverture des PDF dans la visionneuse du navigateur est autorisée par précaution (`object-src` / `frame-src` : `blob:`).
- L'envoi réel du courriel « mot de passe oublié » (SMTP) et l'accès depuis un téléphone sur le réseau local.
- L'exemple Nginx ci-dessus.

## 12. Risques résiduels et pistes

1. **Jeton en `localStorage`** : un script injecté pourrait le lire. Mitigé par la CSP, l'absence de `v-html`, la durée limitée et l'inactivité. Alternative plus forte : mode SPA de Sanctum avec cookie `HttpOnly` (frontend et API sous le même domaine) — non retenue car impossible à tester ici sans risquer de casser l'application.
2. **Preuve d'identité à l'enrôlement faible** (matricule + date d'incorporation, informations connues des collègues) : limitée par les plafonds et les messages uniformes ; en production, préférer une **validation par l'administrateur** ou une confirmation par courriel avant activation du compte.
3. **Pas de désactivation de compte** (on ne peut que supprimer ou changer le mot de passe/rôle) : ajouter une colonne `actif` si le besoin se présente.
4. `/mouvements` n'est pas paginé côté serveur (consommation de ressources si l'historique devient très grand).
5. Le temps de réponse de « mot de passe oublié » peut différer légèrement selon que le courriel est réellement envoyé (fuite par temporisation, faible, déjà plafonnée à 3 requêtes/min/IP) ; la solution est d'envoyer le courriel via une file d'attente.
6. **Pas d'alerte automatique** : le journal est consulté à la main. Piste : courriel à l'administrateur après N blocages ou accès refusés.
7. Le blocage par compte peut être utilisé pour enquiquiner un utilisateur (déni de service ciblé, 15 min). Compromis assumé : le blocage par IP + compte limite l'effet.
