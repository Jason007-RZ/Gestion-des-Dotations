<?php

// Messages de validation en français (APP_LOCALE=fr). Seules les règles utilisées par l'application sont traduites.
return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'array' => 'Le champ :attribute doit être une liste.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'date_format' => 'Le champ :attribute doit être au format :format.',
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists' => "La valeur choisie pour :attribute n'existe pas.",
    'in' => 'La valeur choisie pour :attribute est invalide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array' => 'Le champ :attribute doit contenir au moins :min élément(s).',
    ],
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'string' => 'Le champ :attribute doit être un texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',

    'attributes' => [
        'code' => 'code', 'libelle' => 'libellé', 'designation' => 'désignation', 'categorie_id' => 'catégorie',
        'grille_taille_id' => 'grille de taille', 'duree_vie_mois' => 'durée de vie', 'a_reintegrer' => 'à réintégrer',
        'actif' => 'actif', 'categorie_personnel_id' => 'catégorie de personnel', 'article_id' => 'article',
        'quantite_reglementaire' => 'quantité réglementaire', 'periodicite_mois' => 'périodicité', 'valeur' => 'valeur',
        'ordre' => 'ordre', 'unite_id' => 'unité', 'source_id' => 'catégorie à copier', 'cible_id' => 'catégorie cible',
        'matricule' => 'matricule', 'nom' => 'nom', 'prenoms' => 'prénoms', 'grade_id' => 'grade',
        'date_incorporation' => "date d'incorporation", 'statut' => 'statut', 'motif' => 'motif', 'date_mesure' => 'date de mesure',
        'reference' => 'référence', 'date' => 'date', 'lignes' => 'lignes', 'quantite' => 'quantité', 'seuil_alerte' => "seuil d'alerte",
        'magasin_id' => 'magasin', 'militaire_id' => 'militaire', 'type' => 'type', 'taille_id' => 'taille',
        'pv_numero' => 'numéro du PV', 'imputable' => 'imputabilité', 'observation' => 'observation',
        'email' => 'adresse e-mail', 'password' => 'mot de passe', 'name' => 'nom',
    ],
];
