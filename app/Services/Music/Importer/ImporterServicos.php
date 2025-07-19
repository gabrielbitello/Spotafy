<?php
namespace App\Services\Music\Importer;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;


class ImporterServicos {
    public function calcularDuracao(string $arquivoMp3): int
    {
        $comando = [
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $arquivoMp3
        ];

        $process = new Process($comando);
        $process->run();

        if ($process->isSuccessful()) {
            return (int) round(floatval($process->getOutput()));
        }

        return 0;
    }

    public function detectarExtensaoImagem(string $url): string
    {
        $extensoes = ['.jpg', '.jpeg', '.png', '.webp'];
        
        foreach ($extensoes as $ext) {
            if (strpos(strtolower($url), $ext) !== false) {
                return $ext;
            }
        }
        
        return '.jpg'; // Padrão
    }

    public function verificaDiretorioExiste(string $pastaDestino): void
    {
        if (!is_dir($pastaDestino)) {
            mkdir($pastaDestino, 0755, true);
        }
    }

    public function mesclarDados(array $metadados, ?array $dadosSpotify): array
    {
        $dados = [
            'titulo' => $dadosSpotify['titulo'] ?? $metadados['titulo'] ?? 'Título Desconhecido',
            'artista' => $dadosSpotify['artista'] ?? $metadados['artista'] ?? 'Artista Desconhecido',
            'album' => $dadosSpotify['album'] ?? null,
            'ano' => $dadosSpotify['ano'] ?? null,
            'genero' => $dadosSpotify['genero'] ?? null,
            'duracao' => $metadados['duracao'] ?? null,
            'capa_url' => $dadosSpotify['capa_url'] ?? null,
            'spotify_id' => $dadosSpotify['spotify_id'] ?? null,
            'spotify_url' => $dadosSpotify['spotify_url'] ?? null,
            'popularidade' => $dadosSpotify['popularidade'] ?? null,
            'preview_url' => $dadosSpotify['preview_url'] ?? null,
        ];

        Log::info("📋 Dados mesclados:");
        Log::info("   Título: " . $dados['titulo']);
        Log::info("   Artista: " . $dados['artista']);
        Log::info("   Album: " . ($dados['album'] ?? 'N/A'));
        Log::info("   Gênero: " . ($dados['genero'] ?? 'N/A'));

        return $dados;
    }
}