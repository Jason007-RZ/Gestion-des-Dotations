<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Motif d'une réforme, ou militaire concerné par une réintégration (affiché dans le journal du stock). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->string('motif')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', fn (Blueprint $table) => $table->dropColumn('motif'));
    }
};
