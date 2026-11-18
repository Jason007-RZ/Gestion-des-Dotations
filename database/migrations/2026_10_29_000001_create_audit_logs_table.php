<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'audit : écriture seule (aucune modification ni suppression par l'application).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nom')->nullable();     // copie du nom : reste lisible si le compte est supprimé
            $table->string('action', 60)->index();
            $table->string('cible_type', 60)->nullable();
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->string('resume');
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
