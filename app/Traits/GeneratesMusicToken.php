<?php
// app/Traits/GeneratesMusicToken.php

namespace App\Traits;

use Illuminate\Support\Str;

trait GeneratesMusicToken
{
    /**
     * Gera um token determinístico baseado em artista, música e data
     */
    public static function generateMusicToken(string $artista, string $titulo, ?string $dataLancamento = null): string
    {
        // Normalizar strings (remover acentos, espaços, caracteres especiais)
        $artistaNormalizado = self::normalizeString($artista);
        $tituloNormalizado = self::normalizeString($titulo);
        $dataNormalizada = $dataLancamento ? self::normalizeDate($dataLancamento) : 'unknown';
        
        // Criar string base para hash
        $baseString = "{$artistaNormalizado}_{$tituloNormalizado}_{$dataNormalizada}";
        
        // Gerar hash SHA-256 e pegar os primeiros 32 caracteres + prefixo
        $hash = hash('sha256', $baseString);
        $shortHash = substr($hash, 0, 32);
        
        // Adicionar prefixo para identificação
        return 'mus_' . $shortHash;
    }
    
    /**
     * Normaliza string removendo acentos, espaços e caracteres especiais
     */
    private static function normalizeString(string $input): string
    {
        // Converter para minúsculas
        $normalized = mb_strtolower($input, 'UTF-8');
        
        // Remover acentos
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        
        // Manter apenas letras, números e underscores
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized);
        
        // Remover underscores múltiplos
        $normalized = preg_replace('/_+/', '_', $normalized);
        
        // Remover underscores do início e fim
        return trim($normalized, '_');
    }
    
    /**
     * Normaliza data para formato consistente
     */
    private static function normalizeDate(?string $date): string
    {
        if (!$date) {
            return 'unknown';
        }
        
        try {
            // Tentar converter para Y-m-d
            $carbonDate = \Carbon\Carbon::parse($date);
            return $carbonDate->format('Y-m-d');
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}