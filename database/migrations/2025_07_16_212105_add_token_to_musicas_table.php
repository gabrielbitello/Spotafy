<?php
// database/migrations/2025_01_16_000001_add_token_to_musicas_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('musicas', function (Blueprint $table) {
            $table->string('token', 64)->unique()->after('id'); // Token único de 64 caracteres
            $table->index('token'); // Índice para busca rápida
        });
    }

    public function down(): void
    {
        Schema::table('musicas', function (Blueprint $table) {
            $table->dropIndex(['token']);
            $table->dropColumn('token');
        });
    }
};