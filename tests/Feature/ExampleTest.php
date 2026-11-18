<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Vérifications de base de l'API (remplace le test d'exemple de Laravel, qui attendait une page d'accueil
 * alors que ce projet n'est qu'une API).
 */
class ExampleTest extends TestCase
{
    public function test_le_serveur_repond(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_l_api_exige_une_authentification(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/militaires')->assertUnauthorized();
    }

    public function test_il_n_y_a_pas_de_page_publique(): void
    {
        $this->get('/')->assertNotFound();
    }
}