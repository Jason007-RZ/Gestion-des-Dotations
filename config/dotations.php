<?php

/*
|--------------------------------------------------------------------------
| Règles de gestion modifiables sans toucher au code
|--------------------------------------------------------------------------
*/

return [

    // Grilles de taille utilisées par les mensurations, désignées par leur libellé (modifiable si l'administrateur
    // renomme une grille). Exposées au frontend par GET /referentiels (« grilles_mensuration »).
    'grilles_mensuration' => [
        'taille_vetement' => env('GRILLE_VETEMENT', 'Vêtement'),
        'pointure' => env('GRILLE_POINTURE', 'Pointure'),
        'tour_tete' => env('GRILLE_TOUR_TETE', 'Tour de tête'),
    ],

    // Fenêtre « bientôt » du tableau de bord : échéances dans les N prochains jours (cahier : 30 jours).
    'echeance_proche_jours' => (int) env('ECHEANCE_PROCHE_JOURS', 30),
];
