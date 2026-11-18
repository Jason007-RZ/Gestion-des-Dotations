<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Régiments et services. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('libelle');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unites');
    }
};
