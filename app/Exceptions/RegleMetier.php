<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Refus d'une règle de gestion du cahier (stock insuffisant, double dotation, clôture impossible…).
 * Rendue en 422 { "message": "…" } (bootstrap/app.php) : le frontend affiche le message tel quel.
 */
class RegleMetier extends RuntimeException
{
}
