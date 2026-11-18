<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règle de dotation (cœur du projet) : ce que chaque catégorie de personnel a droit de percevoir, et à quel rythme.
 * periodicite_mois vide : article réintégrable, sans renouvellement périodique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tableau_dotation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categorie_personnel_id')->constrained('categories_personnel')->restrictOnDelete();
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();
            $table->unsignedSmallInteger('quantite_reglementaire');
            $table->unsignedSmallInteger('periodicite_mois')->nullable();
            $table->timestamps();
            $table->unique(['categorie_personnel_id', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tableau_dotation');
    }
};
