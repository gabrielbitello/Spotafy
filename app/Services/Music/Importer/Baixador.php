<?php
namespace App\Services\Music\Importer;

use Symfony\Component\Process\Process;

class Baixador {
    protected string $pastaDestino;

    public function __construct($pastaDestino = null)
    {
        $this->pastaDestino = $pastaDestino ?? storage_path('app/public/musicas');
    }

    public function baixarAudioYoutube(string $query): string
    {
        $comando = [
            'yt-dlp',
            '-f', 'bestaudio',
            '--extract-audio',
            '--audio-format', 'mp3',
            '--audio-quality', '0', // Melhor qualidade
            '-o', $this->pastaDestino . '/%(title)s.%(ext)s',
            '--print', 'after_move:filepath', // Retorna o caminho do arquivo
            'ytsearch1:' . $query,
        ];

        $process = new Process($comando);
        $process->setTimeout(300); // 5 minutos timeout
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception("Erro ao baixar do YouTube: " . $process->getErrorOutput());
        }

        // Encontrar o arquivo mais recente
        $arquivos = glob($this->pastaDestino . '/*.mp3');
        $arquivoMaisRecente = collect($arquivos)
            ->sortByDesc(fn($f) => filemtime($f))
            ->first();

        if (!$arquivoMaisRecente) {
            throw new \Exception("Nenhum arquivo MP3 encontrado após download.");
        }

        return $arquivoMaisRecente;
    }
}