<?php

/*
|--------------------------------------------------------------------------
| CORS — qui a le droit d'appeler l'API depuis un navigateur
|--------------------------------------------------------------------------
| Liste blanche : en production, renseigner CORS_ALLOWED_ORIGINS dans .env avec l'adresse exacte du
| frontend (ex. https://dotations.exemple.mg), séparées par des virgules. Jamais « * ».
| En développement (APP_ENV=local), les adresses de réseau local (localhost, 192.168.x.x, 10.x.x.x,
| 172.16-31.x.x) sont acceptées pour pouvoir tester depuis un téléphone.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173,http://localhost:4173,http://127.0.0.1:4173'
    ))))),

    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d{2,5})?$#']
        : [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    // Authentification par jeton « Bearer » (pas de cookie) : aucun cookie n'est envoyé entre les sites.
    'supports_credentials' => false,

];
