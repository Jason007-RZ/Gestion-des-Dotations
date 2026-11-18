<?php

namespace Database\Factories;

use App\Models\Unite;
use Illuminate\Database\Eloquent\Factories\Factory;

class UniteFactory extends Factory
{
    protected $model = Unite::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('UN-###')),
            'libelle' => $this->faker->unique()->randomElement([
                '1er Régiment des Forces d\'Intervention', '2e Régiment du Génie', 'Bataillon des Transmissions',
                'Bataillon Logistique', 'École des Sous-Officiers', 'Centre d\'instruction des recrues',
            ]),
        ];
    }
}
