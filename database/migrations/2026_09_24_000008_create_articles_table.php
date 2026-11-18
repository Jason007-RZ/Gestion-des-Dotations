<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des effets dotables. grille_taille_id vide : article à taille unique (ceinturon, gourde…).
 * a_reintegrer : l'article doit être rendu à la mutation ou à la radiation (sac à dos, couverture…).
 * actif : un article retiré du catalogue n'est plus proposé, mais son historique reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('designation');
            $table->foreignId('categorie_id')->constrained('categories_article')->restrictOnDelete();
            $table->foreignId('grille_taille_id')->nullable()->constrained('grilles_taille')->restrictOnDelete();
            $table->unsignedSmallInteger('duree_vie_mois')->nullable();
            $table->boolean('a_reintegrer')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
