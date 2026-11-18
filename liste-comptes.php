<?php
use App\Models\User;

echo "Militaires : " . User::where('role', 'militaire')->count() . PHP_EOL;

User::where('role', 'militaire')->orderBy('id')->limit(15)->get()->each(function ($u) {
    echo $u->email . ' | ' . $u->name . PHP_EOL;
});

echo PHP_EOL . "Autres comptes :" . PHP_EOL;
User::where('role', '!=', 'militaire')->orderBy('role')->get()->each(function ($u) {
    echo $u->role . ' | ' . $u->email . PHP_EOL;
});