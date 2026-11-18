<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mouvements de stock : entree (livraison), sortie (bon de dotation), reintegration (retour d'un militaire),
 * reforme (effet usé retiré du stock). Quantité toujours positive ; le type donne le sens.
 * reference : numéro du bon de dotation, du bon de livraison ou du PV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignId('taille_id')->nullable()->constrained('tailles')->restrictOnDelete();
            $table->string('type', 15);
            $table->unsignedInteger('quantite');
            $table->dateTime('date');
            $table->string('reference', 50)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['magasin_id', 'date']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
