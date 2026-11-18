<?php

namespace Database\Factories;

use App\Models\Mensuration;
use App\Models\Militaire;
use App\Models\Taille;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Prend une valeur au hasard dans chaque grille existante (créées par ReferentielsSeeder). */
class MensurationFactory extends Factory
{
    protected $model = Mensuration::class;

    public function definition(): array
    {
        $dans = fn (string $grille) => Taille::whereHas('grille', fn ($q) => $q->where('libelle', $grille))->inRandomOrder()->value('id');

        return [
            'militaire_id' => Militaire::factory(),
            'taille_vetement_id' => $dans('Vêtement'),
            'pointure_id' => $dans('Pointure'),
            'tour_tete_id' => $dans('Tour de tête'),
            'date_mesure' => now()->toDateString(),
        ];
    }
}
