<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensurations : une ligne par prise de mesures. La plus récente (date_mesure) est celle qui sert
 * à préremplir les tailles d'un bon ; les précédentes forment l'historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('militaire_id')->constrained('militaires')->cascadeOnDelete();
            $table->foreignId('taille_vetement_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->foreignId('pointure_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->foreignId('tour_tete_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->date('date_mesure');
            $table->timestamps();
            $table->index(['militaire_id', 'date_mesure']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensurations');
    }
};
