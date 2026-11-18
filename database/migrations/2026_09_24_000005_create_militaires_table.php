<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Personnel. Statut : actif, mute, radie (la mutation et la radiation exigent la réintégration préalable). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('militaires', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 30)->unique();
            $table->string('nom');
            $table->string('prenoms');
            $table->foreignId('grade_id')->constrained('grades')->restrictOnDelete();
            $table->foreignId('unite_id')->constrained('unites')->restrictOnDelete();
            $table->date('date_incorporation');
            $table->string('statut', 10)->default('actif');
            $table->timestamps();
            $table->index(['unite_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('militaires');
    }
};
