<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RG8 : un magasinier ne peut opérer que sur les magasins auxquels il est rattaché.
        Schema::create('magasin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['magasin_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasin_user');
    }
};
