<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dotations : un bon (initiale, renouvellement, remplacement) et ses lignes.
 * date_echeance = date de dotation + periodicite_mois (vide pour un article réintégrable).
 * etat d'une ligne : en_service, reintegre, perdu, reforme.
 * Incidents : perte ou détérioration d'une ligne, avec numéro de PV et imputabilité.
 * incident_id sur la dotation : le remplacement qu'un incident justifie (ajout au modèle du cahier, voir REFONTE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dotations', function (Blueprint $table) {
            $table->id();
            $table->string('numero_bon', 30)->unique();
            $table->foreignId('militaire_id')->constrained('militaires')->restrictOnDelete();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->string('type', 15);
            $table->date('date');
            $table->foreignId('magasinier_id')->constrained('users')->restrictOnDelete();
            $table->text('observation')->nullable();
            $table->timestamps();
            $table->index(['militaire_id', 'date']);
        });

        Schema::create('dotation_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dotation_id')->constrained('dotations')->restrictOnDelete();
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignId('taille_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->unsignedSmallInteger('quantite');
            $table->date('date_echeance')->nullable();
            $table->string('etat', 15)->default('en_service');
            $table->timestamps();
            $table->index(['article_id', 'etat']);
            $table->index(['etat', 'date_echeance']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dotation_ligne_id')->constrained('dotation_lignes')->restrictOnDelete();
            $table->string('type', 15);
            $table->date('date');
            $table->string('pv_numero', 30)->unique();
            $table->boolean('imputable');
            $table->text('observation')->nullable();
            $table->timestamps();
        });

        Schema::table('dotations', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('type')->constrained('incidents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dotations', fn (Blueprint $table) => $table->dropConstrainedForeignId('incident_id'));
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('dotation_lignes');
        Schema::dropIfExists('dotations');
    }
};
