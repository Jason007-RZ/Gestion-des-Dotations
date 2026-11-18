<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock par magasin, article et taille (taille vide pour un article à taille unique).
 * Quantité jamais négative (colonne non signée + contrôle applicatif).
 * L'unicité (magasin, article, taille) est garantie par l'application : MySQL accepte plusieurs NULL dans un index unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignId('taille_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->unsignedInteger('quantite')->default(0);
            $table->unsignedInteger('seuil_alerte')->default(0);
            $table->timestamps();
            $table->unique(['magasin_id', 'article_id', 'taille_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
