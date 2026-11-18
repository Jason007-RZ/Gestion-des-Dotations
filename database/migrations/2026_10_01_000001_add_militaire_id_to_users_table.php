<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lien vers la fiche personnelle, utilisé par le compte de rôle "militaire"
            // pour le self-service (consultation de sa propre dotation). Un seul compte
            // par fiche militaire (unique) : pas d'enrôlement en double sur la même fiche.
            $table->foreignId('militaire_id')->nullable()->unique()->after('unite_id')
                ->constrained('militaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('militaire_id');
        });
    }
};
