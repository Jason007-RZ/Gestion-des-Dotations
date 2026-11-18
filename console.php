<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge des jetons d'accès expirés (nécessite le planificateur : php artisan schedule:work, ou une tâche planifiée).
// Sans lui, rien n'est dangereux : un jeton expiré est de toute façon refusé, il reste juste en base.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
