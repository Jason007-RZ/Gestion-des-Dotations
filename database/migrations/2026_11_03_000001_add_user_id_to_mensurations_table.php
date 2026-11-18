<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Qui a pris les mesures (affiché dans l'historique de la fiche ; cahier : traçabilité de chaque opération). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensurations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('date_mesure')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mensurations', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
