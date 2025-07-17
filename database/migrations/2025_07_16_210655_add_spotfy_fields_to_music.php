<?php
// database/migrations/2025_01_16_000000_add_spotify_fields_to_musicas_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('musicas', function (Blueprint $table) {
            // Aumentar tamanho do título
            $table->string('titulo', 255)->change(); // De 24 para 255 caracteres
            
            // Adicionar campos do Spotify
            $table->string('spotify_id')->nullable()->after('duracao');
            $table->tinyInteger('popularidade')->unsigned()->default(0)->after('spotify_id'); // 0-100
            $table->date('data_lancamento')->nullable()->after('popularidade');
            $table->boolean('explicit')->default(false)->after('data_lancamento');
            $table->text('preview_url')->nullable()->after('explicit'); // URLs podem ser longas
            $table->string('isrc', 12)->nullable()->after('preview_url'); // ISRC tem 12 caracteres
            
            // Índices para melhor performance
            $table->index('spotify_id');
            $table->index('data_lancamento');
            $table->index('popularidade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('musicas', function (Blueprint $table) {
            // Remover índices
            $table->dropIndex(['spotify_id']);
            $table->dropIndex(['data_lancamento']);
            $table->dropIndex(['popularidade']);
            
            // Remover colunas
            $table->dropColumn([
                'spotify_id',
                'popularidade',
                'data_lancamento',
                'explicit',
                'preview_url',
                'isrc'
            ]);
            
            // Voltar título para 24 caracteres (opcional)
            $table->string('titulo', 24)->change();
        });
    }
};