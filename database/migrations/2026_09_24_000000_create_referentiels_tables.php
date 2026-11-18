<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels (cahier des charges §1) : catégories d'articles, grilles de taille et leurs valeurs,
 * catégories de personnel et grades.
 */
return new class extends Migration
{
    public function up(): void
    {
        // HAB habillement, COI coiffures, CHA chaussures, PEQ petit équipement, CAMP campagne, COU couchage
        Schema::create('categories_article', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle');
            $table->timestamps();
        });

        // Vêtement (S, M, L…), pointure (38 à 46), tour de tête (54 à 60)
        Schema::create('grilles_taille', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->timestamps();
        });

        Schema::create('tailles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grille_taille_id')->constrained('grilles_taille')->cascadeOnDelete();
            $table->string('valeur', 20);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
            $table->unique(['grille_taille_id', 'valeur']);
        });

        // Recrue, militaire du rang, sous-officier, officier
        Schema::create('categories_personnel', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->foreignId('categorie_personnel_id')->constrained('categories_personnel')->restrictOnDelete();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
        Schema::dropIfExists('categories_personnel');
        Schema::dropIfExists('tailles');
        Schema::dropIfExists('grilles_taille');
        Schema::dropIfExists('categories_article');
    }
};
