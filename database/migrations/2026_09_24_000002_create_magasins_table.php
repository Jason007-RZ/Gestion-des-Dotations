<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Magasins d'habillement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasins', function (Blueprint $table) {
            $table->id();
            $table->string('libelle');
            $table->foreignId('unite_id')->nullable()->constrained('unites')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasins');
    }
};
