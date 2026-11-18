<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan migrate:fresh --seed : référentiels du cahier + données FICTIVES de démonstration.
 * En production : php artisan db:seed --class=ReferentielsSeeder (référentiels seuls), puis securite:creer-admin.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Seeder de démonstration interdit en production. Lancez seulement : php artisan db:seed --class=ReferentielsSeeder, puis php artisan securite:creer-admin');
        }

        $this->call(DemoSeeder::class);
    }
}
