<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\CategorieArticle;
use App\Models\CategoriePersonnel;
use App\Models\Grade;
use App\Models\GrilleTaille;
use App\Models\TableauDotation;
use App\Models\Taille;
use Illuminate\Database\Seeder;

/**
 * Référentiels du cahier des charges. Sans compte ni donnée personnelle : utilisable aussi en production.
 * Relançable sans doublon (firstOrCreate / updateOrCreate).
 *
 * Le tableau de dotation de la recrue reprend l'EXEMPLE du cahier : quantités et périodicités
 * à remplacer par le tableau réglementaire officiel (écran Administration > Tableau de dotation).
 */
class ReferentielsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'HAB' => 'Habillement', 'COI' => 'Coiffures', 'CHA' => 'Chaussures',
            'PEQ' => 'Petit équipement', 'CAMP' => 'Campagne', 'COU' => 'Couchage',
        ] as $code => $libelle) {
            CategorieArticle::updateOrCreate(['code' => $code], ['libelle' => $libelle]);
        }

        $grilles = [
            'Vêtement' => ['S', 'M', 'L', 'XL', 'XXL'],
            'Pointure' => array_map('strval', range(38, 46)),
            'Tour de tête' => array_map('strval', range(54, 60)),
        ];
        foreach ($grilles as $libelle => $valeurs) {
            $grille = GrilleTaille::firstOrCreate(['libelle' => $libelle]);
            foreach ($valeurs as $i => $valeur) {
                Taille::updateOrCreate(['grille_taille_id' => $grille->id, 'valeur' => $valeur], ['ordre' => $i + 1]);
            }
        }

        // Grades : à ajuster au besoin depuis l'écran d'administration.
        $grades = [
            'Recrue' => ['Recrue'],
            'Militaire du rang' => ['Soldat de 2e classe', 'Soldat de 1re classe', 'Caporal', 'Caporal-chef'],
            'Sous-officier' => ['Sergent', 'Sergent-chef', 'Adjudant', 'Adjudant-chef'],
            'Officier' => ['Aspirant', 'Sous-lieutenant', 'Lieutenant', 'Capitaine', 'Commandant', 'Lieutenant-colonel', 'Colonel', 'Général'],
        ];
        $ordre = 0;
        foreach ($grades as $categorie => $libelles) {
            $cat = CategoriePersonnel::firstOrCreate(['libelle' => $categorie]);
            foreach ($libelles as $libelle) {
                Grade::updateOrCreate(['libelle' => $libelle], ['categorie_personnel_id' => $cat->id, 'ordre' => ++$ordre]);
            }
        }

        $cat = CategorieArticle::pluck('id', 'code');
        $grille = GrilleTaille::pluck('id', 'libelle');

        // code => [désignation, catégorie, grille, durée de vie (mois, vide = réintégrable), à réintégrer, qté recrue]
        $catalogue = [
            'HAB-TRE' => ['Treillis (veste + pantalon)', 'HAB', 'Vêtement', 12, false, 2],
            'HAB-TSK' => ['Tee-shirt kaki', 'HAB', 'Vêtement', 6, false, 3],
            'HAB-SPO' => ['Tenue de sport', 'HAB', 'Vêtement', 12, false, 1],
            'COI-BER' => ['Béret', 'COI', 'Tour de tête', 24, false, 1],
            'CHA-RAN' => ['Rangers (paire)', 'CHA', 'Pointure', 18, false, 1],
            'CHA-SPO' => ['Chaussures de sport (paire)', 'CHA', 'Pointure', 12, false, 1],
            'HAB-CHT' => ['Chaussettes (paire)', 'HAB', null, 6, false, 4],
            'PEQ-CEI' => ['Ceinturon', 'PEQ', null, 36, false, 1],
            'CAMP-SAC' => ['Sac à dos', 'CAMP', null, null, true, 1],
            'CAMP-GOU' => ['Gourde + quart', 'CAMP', null, null, true, 1],
            'COU-MOU' => ['Moustiquaire', 'COU', null, 24, false, 1],
            'COU-COU' => ['Couverture', 'COU', null, null, true, 1],
        ];

        $recrue = CategoriePersonnel::where('libelle', 'Recrue')->firstOrFail();

        foreach ($catalogue as $code => [$designation, $categorie, $grilleLibelle, $duree, $aReintegrer, $quantite]) {
            $article = Article::updateOrCreate(['code' => $code], [
                'designation' => $designation,
                'categorie_id' => $cat[$categorie],
                'grille_taille_id' => $grilleLibelle ? $grille[$grilleLibelle] : null,
                'duree_vie_mois' => $duree,
                'a_reintegrer' => $aReintegrer,
                'actif' => true,
            ]);

            // firstOrCreate : ne pas écraser les valeurs officielles saisies ensuite par l'administrateur.
            TableauDotation::firstOrCreate(
                ['categorie_personnel_id' => $recrue->id, 'article_id' => $article->id],
                ['quantite_reglementaire' => $quantite, 'periodicite_mois' => $duree],
            );
        }
    }
}
