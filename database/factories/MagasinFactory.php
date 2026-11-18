<?php

namespace Database\Factories;

use App\Models\Magasin;
use App\Models\Unite;
use Illuminate\Database\Eloquent\Factories\Factory;

class MagasinFactory extends Factory
{
    protected $model = Magasin::class;

    public function definition(): array
    {
        return [
            'libelle' => 'Magasin d\'habillement — '.$this->faker->city(),
            'unite_id' => Unite::factory(),
        ];
    }
}
