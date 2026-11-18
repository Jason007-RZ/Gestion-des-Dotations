<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// À exécuter après la migration par défaut create_users_table de Laravel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // administrateur | magasinier | chef_unite | militaire
            $table->string('role')->default('militaire')->after('email');
            $table->foreignId('unite_id')->nullable()->after('role')
                ->constrained('unites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unite_id');
            $table->dropColumn('role');
        });
    }
};
