<?php
namespace App\Services\Music\spotify;
use App\Services\Music\spotify\SpotifyCore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use FuzzyWuzzy\Fuzz;
use FuzzyWuzzy\Process;
use Illuminate\Support\Str;


class SpotifyPesquisa {

    private $spotifyCore;

    public function __construct()
    {
        $this->spotifyCore = new SpotifyCore();
    }

    /**
    * Método principal de busca com todas as estratégias
    */
    public function buscarDadosSpotify(string $titulo): ?array
    {
        Log::info("🎯 Iniciando busca completa no Spotify");
        
        $resultado = $this->buscarSpotifyPorTituloCompleto($titulo);
        
        if ($resultado) return $resultado;
        Log::info("❌ Nenhuma estratégia encontrou resultados");
        return null;
    }

    /**
    * Busca ampla quando só temos o título completo
    */
    public function buscarSpotifyPorTituloCompleto(string $tituloCompleto): ?array
    {
        Log::info("🔍 Busca ampla no Spotify com: '{$tituloCompleto}'");

        if (!$this->spotifyCore->ensureValidSpotifyToken()) {
            return null;
        }

        $querie = "\"{$tituloCompleto}\"";

        try {
            $response = Http::withToken($this->spotifyCore->spotifyToken)
                ->timeout(15)
                ->get("https://api.spotify.com/v1/search", [
                    'q' => $querie,
                    'type' => 'track',
                    'limit' => 20, // Mais resultados para busca ampla
                    'market' => 'BR'
                ]);

            if ($response->successful() && !empty($response->json('tracks.items'))) {
                $tracks = $response->json('tracks.items');
                
                // Para busca ampla, usar threshold menor (40%)
                $bestMatch = $this->findBestTrackMatchFlexible($tracks, $tituloCompleto);
                if ($bestMatch) {
                    Log::info("✅ Match encontrado com busca ampla");
                    return $bestMatch; // <- já está formatado
                }
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ Erro na busca ampla: " . $e->getMessage());
        }

        return null;
    }

    /**
    * Match mais flexível para busca ampla
    */
    private function findBestTrackMatchFlexible(array $tracks, string $targetText): ?array
    {
        Log::info("🔍 Iniciando matching fuzzy com '{$targetText}'");

        $fuzz = new Fuzz();
        $process = new Process($fuzz);

        $targetNormalized = $this->normalizeForSimilarity($targetText);

        $choicesMap = [];
        $choicesText = [];

        foreach ($tracks as $index => $track) {
            $artist = $track['artists'][0]['name'] ?? '';
            $title = $track['name'] ?? '';
            $composite = "{$artist} - {$title}";
            $normalized = $this->normalizeForSimilarity($composite);

            $choicesText[] = $normalized;
            $choicesMap[$normalized] = $track;
        }

        if (empty($choicesText)) {
            Log::info("⚠️ Nenhuma música para comparar");
            return null;
        }

        // Primeiro: tentativa com tokenSetRatio (mais completo)
        $result = $process->extractOne($targetNormalized, $choicesText, null, [$fuzz, 'tokenSetRatio']);
        $score = $result[1] ?? 0;

        // Se estiver abaixo de 70, tenta com partialRatio como fallback
        if ($score < 70) {
            Log::info("🔁 Score com tokenSetRatio abaixo de 70 ({$score}%). Tentando com partialRatio...");
            $result = $process->extractOne($targetNormalized, $choicesText, null, [$fuzz, 'partialRatio']);
            $score = $result[1] ?? 0;
        }

        // Define threshold mínimo
        $limiarAceitavel = 65;
        if ($score < $limiarAceitavel) {
            Log::info("❌ Nenhuma correspondência com score aceitável. Melhor resultado: {$result[0]} ({$score}%)");
            return null;
        }

        $matchedKey = $result[0];
        $matchedTrack = $choicesMap[$matchedKey] ?? null;

        if (!$matchedTrack) {
            Log::warning("⚠️ Track correspondente ao match fuzzy não encontrado.");
            return null;
        }

        Log::info("✅ Melhor match fuzzy: '{$matchedKey}' com {$score}% de similaridade");
        return $this->formatSpotifyTrackData($matchedTrack);
    }

    /**
    * Formata dados da track do Spotify
    */
    private function formatSpotifyTrackData(array $track): array
    {
        // Buscar gêneros do artista
        $generos = [];
        if (!empty($track['artists'][0]['id'])) {
            $generos = $this->buscarGenerosArtista($track['artists'][0]['id']);
        }
        return [
            'artista' => $track['artists'][0]['name'] ?? null,
            'titulo' => $track['name'] ?? null,
            'album' => $track['album']['name'] ?? null,
            'duracao_ms' => $track['duration_ms'] ?? null,
            'popularidade' => $track['popularity'] ?? null,
            'spotify_id' => $track['id'] ?? null,
            'capa_url' => $this->getBestImageUrl($track['album']['images'] ?? []),
            'data_lancamento' => $track['album']['release_date'] ?? null,
            'generos' => $generos,
            'preview_url' => $track['preview_url'] ?? null,
            'explicit' => $track['explicit'] ?? false,
            'isrc' => $track['external_ids']['isrc'] ?? null,
            'spotify_url' => $track['external_urls']['spotify'] ?? null
        ];
    }

    /**
    * Normaliza string para comparação de similaridade (remove acentos e minúsculas)
    */
    private function normalizeForSimilarity(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        return $str;
    }

    /**
    * Busca gêneros do artista com cache
    */
    private function buscarGenerosArtista(string $artistaId): array
    {
        $cacheKey = "spotify_artist_genres_{$artistaId}";
        return Cache::remember($cacheKey, 3600, function () use ($artistaId) {
            try {
                if (!$this->spotifyCore->ensureValidSpotifyToken()) {
                    return [];
                }
                $response = Http::withToken($this->spotifyCore->spotifyToken)
                    ->timeout(10)
                    ->get("https://api.spotify.com/v1/artists/{$artistaId}");
                if ($response->successful()) {
                    $genres = $response->json('genres', []);
                    Log::info("🎭 Gêneros encontrados para artista {$artistaId}: " . implode(', ', $genres));
                    return $genres;
                } else {
                    Log::warning("⚠️ Erro ao buscar gêneros do artista {$artistaId}: " . $response->status());
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Exceção ao buscar gêneros do artista: " . $e->getMessage());
            }
            return [];
        });
    }

    /**
    * Seleciona a melhor URL de imagem disponível
    */
    private function getBestImageUrl(array $images): ?string
    {
        if (empty($images)) {
            return null;
        }

        // Ordenar por tamanho (maior primeiro)
        usort($images, function ($a, $b) {
            return ($b['width'] ?? 0) <=> ($a['width'] ?? 0);
        });

        // Preferir imagens entre 300-640px para melhor qualidade/performance
        foreach ($images as $image) {
            $width = $image['width'] ?? 0;
            if ($width >= 300 && $width <= 640) {
                return $image['url'];
            }
        }

        // Fallback para a primeira imagem
        return $images[0]['url'] ?? null;
    }
}