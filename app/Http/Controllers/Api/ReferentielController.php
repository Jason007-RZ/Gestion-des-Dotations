<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Referentiels;

class ReferentielController extends Controller
{
    /** GET /referentiels : toutes les listes de référence (tous les rôles connectés). */
    public function index()
    {
        return response()->json(Referentiels::tout());
    }
}
