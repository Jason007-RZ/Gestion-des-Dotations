<?php

namespace Database\Factories;

use App\Models\CategoriePersonnel;
use App\Models\Grade;
use App\Models\Militaire;
use App\Models\Unite;
use Illuminate\Database\Eloquent\Factories\Factory;

class MilitaireFactory extends Factory
{
    protected $model = Militaire::class;

    public function definition(): array
    {
        return [
            'matricule' => strtoupper($this->faker->unique()->bothify('MAT-#####')),
            'nom' => strtoupper($this->faker->lastName()),
            'prenoms' => $this->faker->firstName(),
            'grade_id' => fn () => Grade::query()->inRandomOrder()->value('id')
                ?? Grade::create([
                    'libelle' => 'Recrue',
                    'categorie_personnel_id' => CategoriePersonnel::firstOrCreate(['libelle' => 'Recrue'])->id,
                ])->id,
            'unite_id' => Unite::factory(),
            'date_incorporation' => $this->faker->dateTimeBetween('-3 years', '-1 week')->format('Y-m-d'),
            'statut' => Militaire::STATUT_ACTIF,
        ];
    }
}
