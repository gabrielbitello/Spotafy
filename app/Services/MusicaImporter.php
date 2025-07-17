<?php
namespace App\Services;

use App\Models\Musica;
use App\Models\Artista;
use App\Models\Album;
use App\Models\Genero;
use App\Traits\GeneratesMusicToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Carbon\Carbon;

class MusicaImporter
{
    private $spotifyToken;
    private $spotifyTokenExpires;
    private $pastaDestino;
    private $maxRetries = 3;
    private $retryDelay = 1; // segundos

    public function __construct()
    {
        $this->pastaDestino = storage_path('app/public/musicas');
        $this->ensureDirectoryExists();
        $this->initializeSpotifyAuth();
    }

     /**
     * Inicializa autenticação do Spotify com validação
     */
    private function initializeSpotifyAuth(): void
    {
        if (!$this->validateSpotifyCredentials()) {
            Log::error("❌ Credenciais do Spotify não configuradas ou inválidas");
            return;
        }

        $this->getSpotifyToken();
    }

     /**
     * Valida se as credenciais do Spotify estão configuradas
     */
    private function validateSpotifyCredentials(): bool
    {
        $clientId = config('services.spotify.client_id');
        $clientSecret = config('services.spotify.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error("Credenciais do Spotify não encontradas no config/services.php");
            return false;
        }

        if (strlen($clientId) !== 32 || strlen($clientSecret) !== 32) {
            Log::error("Formato das credenciais do Spotify parece inválido");
            return false;
        }

        return true;
    }

    // app/Services/MusicaImporter.php
    public function importar(string $termoBusca): bool
    {
        try {
            Log::info("🎵 Iniciando importação: {$termoBusca}");

            // Baixar música
            $arquivoMp3 = $this->baixarAudioYoutube($termoBusca);
            if (!$arquivoMp3) {
                throw new \Exception("Não foi possível baixar a música");
            }

            // Extrair metadados do título do arquivo
            $metadados = $this->extrairMetadadosDoTitulo($arquivoMp3);
            
            // Adicionar duração do arquivo
            $duracaoArquivo = $this->calcularDuracao($arquivoMp3);
            $metadados['duracao'] = $duracaoArquivo;
            
            // ✅ NOVA REGRA: Buscar com fallback para termo original
            $dadosSpotify = $this->buscarDadosSpotifyComFallback(
                $metadados['artista'], 
                $metadados['titulo'], 
                $termoBusca  // ← Termo de busca original
            );
            
            // Mesclar dados
            $dados = $this->mesclarDados($metadados, $dadosSpotify);

            // Verificar se música já existe
            $musicaExistente = Musica::where('token', $dados['token'])->first();
            if ($musicaExistente) {
                Log::info("Música já existe com token: {$dados['token']}");
                $this->adicionarAoMediaLibrary($musicaExistente, $arquivoMp3, $dados);
                Log::info("✅ Música importada com sucesso: {$dados['titulo']} - {$dados['artista']}");
                return true;
            }

            // Criar/buscar entidades relacionadas
            $artista = $this->criarOuBuscarArtista($dados['artista']);
            $album = $this->criarOuBuscarAlbum($dados['album'], $artista);
            $generos = $this->criarOuBuscarGeneros($dados['generos'] ?? []);

            // Criar música
            $musica = $this->criarMusica($dados, $artista, $album);
            $this->adicionarAoMediaLibrary($musica, $arquivoMp3, $dados);

            Log::info("✅ Música importada com sucesso: {$dados['titulo']} - {$dados['artista']}");
            return true;

        } catch (\Exception $e) {
            Log::error("❌ Erro na importação: " . $e->getMessage());
            return false;
        }
    }

    private function verificarMusicaExistente(string $query): ?Musica
    {
        return Musica::where('titulo', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('artista', function($q) use ($query) {
                        $q->where('nome', 'LIKE', '%' . $query . '%');
                    })
                    ->first();
    }

    private function baixarAudioYoutube(string $query): string
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

    private function extrairMetadadosDoTitulo(string $arquivoMp3): array
    {
        $nomeArquivo = pathinfo($arquivoMp3, PATHINFO_FILENAME);
        
        Log::info("📂 Extraindo metadados do arquivo: {$nomeArquivo}");
        
        // Patterns mais abrangentes e flexíveis
        $patterns = [
            // Padrão: Artista - Título (mais comum)
            '/^(.+?)\s*[-–—]\s*(.+?)(?:\s*\([^)]*\))?(?:\s*\[[^\]]*\])?(?:\s*\|.*)?(?:\s*official.*)?(?:\s*video.*)?$/ui',
            
            // Padrão: Artista | Título
            '/^(.+?)\s*[|]\s*(.+?)(?:\s*\([^)]*\))?(?:\s*\[[^\]]*\])?$/ui',
            
            // Padrão: Artista • Título
            '/^(.+?)\s*[•]\s*(.+?)(?:\s*\([^)]*\))?(?:\s*\[[^\]]*\])?$/ui',
            
            // Padrão: "Título" by Artista
            '/^["\'](.+?)["\'].*?by\s+(.+?)(?:\s*\([^)]*\))?$/ui',
            
            // Padrão: Artista : Título
            '/^(.+?)\s*[:]\s*(.+?)(?:\s*\([^)]*\))?(?:\s*\[[^\]]*\])?$/ui',
            
            // Padrão: Artista ~ Título
            '/^(.+?)\s*[~]\s*(.+?)(?:\s*\([^)]*\))?(?:\s*\[[^\]]*\])?$/ui',
        ];

        $artista = 'Artista Desconhecido';
        $titulo = $nomeArquivo;
        $matchFound = false;

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $nomeArquivo, $matches)) {
                Log::info("✅ Pattern " . ($index + 1) . " matched");
                
                if ($index === 3) { // "Título" by Artista
                    $titulo = trim($matches[1]);
                    $artista = trim($matches[2]);
                } else {
                    $artista = trim($matches[1]);
                    $titulo = trim($matches[2]);
                }
                
                $matchFound = true;
                break;
            }
        }

        if (!$matchFound) {
            Log::warning("❌ Nenhum pattern encontrado para: {$nomeArquivo}");
            
            // Estratégia de fallback: usar o nome completo como título
            // e tentar uma busca mais ampla no Spotify
            $titulo = $this->limparTextoBasico($nomeArquivo);
            $artista = 'Artista Desconhecido';
            
            Log::info("🔄 Usando nome completo como título para busca ampla");
        }

        // Limpeza básica (sem remover caracteres especiais importantes)
        $artista = $this->limparTextoBasico($artista);
        $titulo = $this->limparTextoBasico($titulo);

        // Validar se não ficaram vazios
        if (empty($artista) || strlen(trim($artista)) < 1) {
            $artista = 'Artista Desconhecido';
        }
        
        if (empty($titulo) || strlen(trim($titulo)) < 1) {
            $titulo = $this->limparTextoBasico($nomeArquivo);
        }

        Log::info("🎵 Metadados extraídos:");
        Log::info("   Artista: '{$artista}'");
        Log::info("   Título: '{$titulo}'");

        return [
            'artista' => $artista,
            'titulo' => $titulo,
            'arquivo_original' => $nomeArquivo
        ];
    }

    /**
     * Limpeza básica que preserva caracteres especiais importantes
     */
    private function limparTextoBasico(string $texto): string
    {
    // Remove apenas caracteres claramente problemáticos, preservando:
    // - Acentos e caracteres unicode
    // - Números
    // - Caracteres especiais comuns em nomes de artistas (., &, +, etc.)
    
    // Remove apenas: caracteres de controle, alguns símbolos problemáticos
    $texto = preg_replace('/[\x00-\x1F\x7F]/', '', $texto); // Caracteres de controle
    $texto = preg_replace('/[<>:"\/\|?*]/', '', $texto); // Caracteres problemáticos para arquivos
    
    // Normaliza espaços múltiplos
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    return trim($texto);
    }

    /**
     * Constrói query otimizada para busca no Spotify
     */
    private function buildSpotifyQuery(string $artista, string $titulo): string
    {
        // Limpar e normalizar termos
        $artistaLimpo = $this->cleanSearchTerm($artista);
        $tituloLimpo = $this->cleanSearchTerm($titulo);

        // Construir query com operadores do Spotify
        $query = 'track:"' . $tituloLimpo . '" artist:"' . $artistaLimpo . '"';
        
        return urlencode($query);
    }

    /**
     * Limpa termos de busca removendo caracteres problemáticos
     */
    private function cleanSearchTerm(string $term): string
    {
        // Remover caracteres especiais que podem interferir na busca
        $term = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $term);
        $term = preg_replace('/\s+/', ' ', $term);
        $term = trim($term);
        
        // Remover palavras comuns que podem interferir
        $stopWords = ['official', 'video', 'music', 'ft', 'feat', 'featuring'];
        foreach ($stopWords as $stopWord) {
            $term = preg_replace('/\b' . preg_quote($stopWord, '/') . '\b/i', '', $term);
        }
        
        return trim($term);
    }

    /**
     * Encontra o melhor match entre os resultados usando similaridade
     */
    private function findBestTrackMatch(array $tracks, string $targetArtist, string $targetTitle): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($tracks as $track) {
            $score = $this->calculateMatchScore($track, $targetArtist, $targetTitle);
            
            if ($score > $bestScore && $score >= 0.7) { // Threshold de 70%
                $bestScore = $score;
                $bestMatch = $track;
            }
        }

        Log::info("🎯 Melhor score encontrado: " . round($bestScore * 100, 2) . "%");
        return $bestMatch;
    }

    /**
     * Calcula score de similaridade entre track e busca
     */
    private function calculateMatchScore(array $track, string $targetArtist, string $targetTitle): float
    {
        $trackTitle = strtolower($track['name']);
        $trackArtist = strtolower($track['artists'][0]['name']);
        $targetTitleLower = strtolower($targetTitle);
        $targetArtistLower = strtolower($targetArtist);

        // Calcular similaridade usando levenshtein
        $titleSimilarity = 1 - (levenshtein($trackTitle, $targetTitleLower) / max(strlen($trackTitle), strlen($targetTitleLower)));
        $artistSimilarity = 1 - (levenshtein($trackArtist, $targetArtistLower) / max(strlen($trackArtist), strlen($targetArtistLower)));

        // Peso: título 60%, artista 40%
        $finalScore = ($titleSimilarity * 0.6) + ($artistSimilarity * 0.4);

        return max(0, $finalScore);
    }

    /**
     * Formata dados da track do Spotify
     */
    private function formatSpotifyTrackData(array $track): array
    {
        // Buscar gêneros do artista
        $generos = $this->buscarGenerosArtista($track['artists'][0]['id']);
        
        return [
            'artista' => $track['artists'][0]['name'],
            'titulo' => $track['name'],
            'album' => $track['album']['name'],
            'duracao_ms' => $track['duration_ms'],
            'popularidade' => $track['popularity'],
            'spotify_id' => $track['id'],
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

    /**
     * Limpa cache do Spotify (útil para debugging)
     */
    public function clearSpotifyCache(): void
    {
        Cache::forget('spotify_token');
        Cache::forget('spotify_token_expires');
        Cache::flush(); // Limpa todos os caches de gêneros também
        
        Log::info("🧹 Cache do Spotify limpo");
    }

     /**
     * Busca alternativa usando diferentes estratégias
     */
    private function buscarSpotifyAlternativo(string $artista, string $titulo): ?array
    {
        Log::info("🔄 Tentando busca alternativa no Spotify...");

        if (!$this->ensureValidSpotifyToken()) {
            return null;
        }

        // Estratégias alternativas de busca
        $strategies = [
            urlencode("\"{$titulo}\""),
            urlencode("artist:\"{$artista}\""),
            urlencode("{$artista} {$titulo}"),
            urlencode($artista . ' ' . $titulo)
        ];

        foreach ($strategies as $index => $query) {
            Log::info("🎯 Estratégia " . ($index + 1) . ": {$query}");
            
            try {
                $response = Http::withToken($this->spotifyToken)
                    ->timeout(10)
                    ->get("https://api.spotify.com/v1/search", [
                        'q' => $query,
                        'type' => 'track',
                        'limit' => 3
                    ]);

                if ($response->successful() && !empty($response->json('tracks.items'))) {
                    $tracks = $response->json('tracks.items');
                    $bestMatch = $this->findBestTrackMatch($tracks, $artista, $titulo);
                    
                    if ($bestMatch) {
                        Log::info("✅ Match encontrado com estratégia " . ($index + 1));
                        return $this->formatSpotifyTrackData($bestMatch);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Erro na estratégia " . ($index + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        return null;
    }
    
    /**
     * Busca música no Spotify com retry automático e rate limiting
     */
    private function buscarNoSpotify(string $artista, string $titulo): ?array
    {
        if (!$this->ensureValidSpotifyToken()) {
            Log::warning("❌ Token do Spotify não disponível");
            return null;
        }

        $query = $this->buildSpotifyQuery($artista, $titulo);
        Log::info("🔍 Buscando no Spotify: {$artista} - {$titulo}");

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::withToken($this->spotifyToken)
                    ->timeout(15)
                    ->retry(2, 1000) // 2 tentativas com 1s de intervalo
                    ->get("https://api.spotify.com/v1/search", [
                        'q' => $query,
                        'type' => 'track',
                        'limit' => 5, // Buscar mais resultados para melhor match
                        'market' => 'BR' // Mercado brasileiro
                    ]);

                // Tratar diferentes códigos de status
                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After', $this->retryDelay);
                    Log::warning("⏳ Rate limit atingido. Aguardando {$retryAfter}s... (Tentativa {$attempt}/{$this->maxRetries})");
                    sleep((int)$retryAfter);
                    continue;
                }

                if ($response->status() === 401) {
                    Log::warning("🔑 Token inválido, renovando... (Tentativa {$attempt}/{$this->maxRetries})");
                    $this->getSpotifyToken();
                    if (!$this->spotifyToken) {
                        return null;
                    }
                    continue;
                }

                if ($response->successful()) {
                    $tracks = $response->json('tracks.items', []);
                    
                    if (empty($tracks)) {
                        Log::info("❌ Nenhuma música encontrada no Spotify para: {$artista} - {$titulo}");
                        return null;
                    }

                    // Encontrar o melhor match
                    $bestMatch = $this->findBestTrackMatch($tracks, $artista, $titulo);
                    
                    if ($bestMatch) {
                        Log::info("✅ Música encontrada no Spotify: " . $bestMatch['name']);
                        return $this->formatSpotifyTrackData($bestMatch);
                    } else {
                        Log::info("❌ Nenhum match adequado encontrado no Spotify");
                        return null;
                    }
                } else {
                    Log::warning("⚠️ Erro na resposta do Spotify: " . $response->status() . " - " . $response->body());
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Erro na tentativa {$attempt}: " . $e->getMessage());
                
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt); // Backoff exponencial
                }
            }
        }

        Log::error("❌ Falha após {$this->maxRetries} tentativas de busca no Spotify");
        return null;
    }

    

    // app/Services/MusicaImporter.php

    private function extrairDadosDoArquivo(string $caminhoArquivo, string $termoBusca): array
    {
        $dados = [
            'titulo' => null,
            'artista' => null,
            'album' => null,
            'genero' => null,
            'ano' => null,
            'duracao' => null,
            'capa_url' => null,
        ];

        // 1️⃣ PRIMEIRO: Tentar buscar no Spotify
        Log::info("=== TENTANDO BUSCAR NO SPOTIFY ===");
        $dadosSpotify = $this->buscarDadosSpotify('', $termoBusca);
        
        if ($dadosSpotify) {
            Log::info("✅ Dados encontrados no Spotify!");
            Log::info("Spotify - Título: " . ($dadosSpotify['titulo'] ?? 'NULL'));
            Log::info("Spotify - Artista: " . ($dadosSpotify['artista'] ?? 'NULL'));
            
            // Usar dados do Spotify
            $dados = array_merge($dados, $dadosSpotify);
            
            // Se conseguiu título e artista do Spotify, retornar
            if (!empty($dados['titulo']) && !empty($dados['artista'])) {
                Log::info("🎵 Usando dados do Spotify como fonte principal");
                return $dados;
            }
        }

        // 2️⃣ FALLBACK: Se Spotify falhou, tentar extrair do arquivo
        Log::info("=== FALLBACK: EXTRAINDO DO ARQUIVO ===");
        
        try {
            $getID3 = new \getID3;
            $fileInfo = $getID3->analyze($caminhoArquivo);

            Log::info("Arquivo: " . $caminhoArquivo);
            
            // Extrair informações das tags ID3
            if (isset($fileInfo['tags']['id3v2'])) {
                $tags = $fileInfo['tags']['id3v2'];
                Log::info("📂 Tags ID3v2 encontradas");
                
                $dados['titulo'] = $dados['titulo'] ?? ($tags['title'][0] ?? null);
                $dados['artista'] = $dados['artista'] ?? ($tags['artist'][0] ?? null);
                $dados['album'] = $dados['album'] ?? ($tags['album'][0] ?? null);
                $dados['genero'] = $dados['genero'] ?? ($tags['genre'][0] ?? null);
                $dados['ano'] = $dados['ano'] ?? (isset($tags['year'][0]) ? (int)$tags['year'][0] : null);
                
            } elseif (isset($fileInfo['tags']['id3v1'])) {
                $tags = $fileInfo['tags']['id3v1'];
                Log::info("📂 Tags ID3v1 encontradas");
                
                $dados['titulo'] = $dados['titulo'] ?? ($tags['title'][0] ?? null);
                $dados['artista'] = $dados['artista'] ?? ($tags['artist'][0] ?? null);
                $dados['album'] = $dados['album'] ?? ($tags['album'][0] ?? null);
                $dados['genero'] = $dados['genero'] ?? ($tags['genre'][0] ?? null);
                $dados['ano'] = $dados['ano'] ?? (isset($tags['year'][0]) ? (int)$tags['year'][0] : null);
            }

            // Extrair duração do arquivo (sempre pegar do arquivo real)
            if (isset($fileInfo['playtime_seconds'])) {
                $dados['duracao'] = (int)$fileInfo['playtime_seconds'];
                Log::info("⏱️ Duração extraída do arquivo: " . $dados['duracao'] . "s");
            }

        } catch (\Exception $e) {
            Log::error("❌ Erro ao extrair dados do arquivo: " . $e->getMessage());
        }

        // 3️⃣ ÚLTIMO RECURSO: Usar termo de busca
        if (empty($dados['titulo']) || empty($dados['artista'])) {
            Log::info("=== ÚLTIMO RECURSO: USANDO TERMO DE BUSCA ===");
            
            if (empty($dados['titulo'])) {
                $dados['titulo'] = $this->extrairTituloDoTermoBusca($termoBusca);
            }
            
            if (empty($dados['artista'])) {
                $dados['artista'] = $this->extrairArtistaDoTermoBusca($termoBusca);
            }
        }

        // LOGS FINAIS
        Log::info("=== DADOS FINAIS ===");
        Log::info("Título: " . ($dados['titulo'] ?? 'NULL'));
        Log::info("Artista: " . ($dados['artista'] ?? 'NULL'));
        Log::info("Album: " . ($dados['album'] ?? 'NULL'));
        Log::info("Gênero: " . ($dados['genero'] ?? 'NULL'));
        Log::info("Ano: " . ($dados['ano'] ?? 'NULL'));
        Log::info("Duração: " . ($dados['duracao'] ?? 'NULL'));
        Log::info("Capa URL: " . ($dados['capa_url'] ?? 'NULL'));
        Log::info("=== FIM ===");

        return $dados;
    }

    // app/Services/MusicaImporter.php

    private function buscarDadosNoSpotify(string $termoBusca): ?array
    {
        try {
            if (!$this->spotifyToken) {
                Log::warning("Token do Spotify não disponível");
                return null;
            }

            Log::info("🔍 Buscando no Spotify: " . $termoBusca);
            
            $query = urlencode($termoBusca);
            $response = Http::withToken($this->spotifyToken)
                ->timeout(10)
                ->get("https://api.spotify.com/v1/search", [
                    'q' => $query,
                    'type' => 'track',
                    'limit' => 1
                ]);

            if ($response->successful() && $response->json('tracks.total') > 0) {
                $track = $response->json('tracks.items.0');
                
                Log::info("✅ Música encontrada no Spotify: " . $track['name']);
                
                // Buscar gêneros do artista
                $generos = $this->buscarGenerosArtista($track['artists'][0]['id']);
                
                return [
                    'titulo' => $track['name'],
                    'artista' => $track['artists'][0]['name'],
                    'album' => $track['album']['name'],
                    'duracao' => (int) round($track['duration_ms'] / 1000), // Converter para segundos
                    'ano' => isset($track['album']['release_date']) ? (int) substr($track['album']['release_date'], 0, 4) : null,
                    'genero' => !empty($generos) ? $generos[0] : null,
                    'capa_url' => isset($track['album']['images'][0]) ? $track['album']['images'][0]['url'] : null,
                ];
            } else {
                Log::info("❌ Nenhuma música encontrada no Spotify para: {$termoBusca}");
            }
            
        } catch (\Exception $e) {
            Log::error("❌ Erro na busca do Spotify: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Busca gêneros do artista com cache
     */
    private function buscarGenerosArtista(string $artistaId): array
    {
        $cacheKey = "spotify_artist_genres_{$artistaId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($artistaId) {
            try {
                if (!$this->ensureValidSpotifyToken()) {
                    return [];
                }

                $response = Http::withToken($this->spotifyToken)
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

    // app/Services/MusicaImporter.php
    private function mesclarDados(array $metadados, ?array $dadosSpotify): array
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

        // ✅ GERAR TOKEN SEMPRE
        $dados['token'] = GeneratesMusicToken::generateMusicToken($dados['artista'], $dados['titulo'], $dados['ano'] ?? null);
        
        Log::info("🔗 Token gerado: " . $dados['token']);
        Log::info("📋 Dados mesclados:");
        Log::info("   Título: " . $dados['titulo']);
        Log::info("   Artista: " . $dados['artista']);
        Log::info("   Album: " . ($dados['album'] ?? 'N/A'));
        Log::info("   Gênero: " . ($dados['genero'] ?? 'N/A'));

        return $dados;
    }

    private function detectarGenerosPorPalavrasChave(string $titulo): array
    {
        $palavrasChave = [
            'funk' => ['funk', 'mc ', 'baile'],
            'trap' => ['trap', 'drill'],
            'rock' => ['rock', 'metal'],
            'pop' => ['pop', 'hit'],
            'rap' => ['rap', 'hip hop', 'freestyle'],
            'eletrônica' => ['remix', 'electronic', 'edm', 'house'],
            'sertanejo' => ['sertanejo', 'modão'],
            'forró' => ['forró', 'xote'],
            'reggae' => ['reggae', 'rasta']
        ];

        $tituloLower = strtolower($titulo);
        $generosDetectados = [];

        foreach ($palavrasChave as $genero => $palavras) {
            foreach ($palavras as $palavra) {
                if (strpos($tituloLower, $palavra) !== false) {
                    $generosDetectados[] = $genero;
                    break;
                }
            }
        }

        return $generosDetectados ?: ['pop']; // Default para pop
    }

    private function calcularDuracao(string $arquivoMp3): int
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

    private function criarOuBuscarArtista(string $nomeArtista): Artista
    {
        return Artista::firstOrCreate(
            ['nome' => $nomeArtista],
            [
                'biografia' => null,
                'data_nascimento' => null,
                'nacionalidade' => null
            ]
        );
    }

    private function criarOuBuscarGeneros(array $nomesGeneros): \Illuminate\Support\Collection
    {
        $generos = collect();

        foreach ($nomesGeneros as $nomeGenero) {
            $genero = Genero::firstOrCreate(['nome' => ucfirst($nomeGenero)]);
            $generos->push($genero);
        }

        return $generos;
    }

    private function criarOuBuscarAlbum(string $nomeAlbum, Artista $artista): Album
    {
        return Album::firstOrCreate(
            [
                'titulo' => $nomeAlbum,
                'artista_id' => $artista->id
            ],
            [
                'data_lancamento' => null,
                'capa' => null
            ]
        );
    }

    // app/Services/MusicaImporter.php
    private function criarMusica(array $dados, Artista $artista, Album $album): Musica
    {
        // Gerar token determinístico
        $token = Musica::generateMusicToken(
            $artista->nome,
            $dados['titulo'],
            $dados['data_lancamento']
        );

        // Verificar se já existe música com este token
        $musicaExistente = Musica::where('token', $token)->first();
        if ($musicaExistente) {
            Log::info("Música já existe com token: {$token}");
            return $musicaExistente; // ← RETORNA O OBJETO, NÃO BOOLEAN
        }

        // Converter duração de milissegundos para segundos se disponível
        $duracaoSegundos = $dados['duracao'];
        if (!$duracaoSegundos && isset($dados['duracao_ms']) && $dados['duracao_ms']) {
            $duracaoSegundos = (int) round($dados['duracao_ms'] / 1000);
        }

        // Converter data de lançamento para Carbon
        $dataLancamento = null;
        if (!empty($dados['data_lancamento'])) {
            try {
                $dataLancamento = Carbon::parse($dados['data_lancamento']);
            } catch (\Exception $e) {
                Log::warning("Erro ao converter data de lançamento: " . $dados['data_lancamento']);
            }
        }

        $novaMusica = Musica::create([
            'token' => $token,
            'titulo' => mb_strimwidth($dados['titulo'], 0, 255, ''),
            'duracao' => $duracaoSegundos ?? 0,
            'artista_id' => $artista->id,
            'album_id' => $album->id,
            'spotify_id' => $dados['spotify_id'],
            'popularidade' => $dados['popularidade'] ?? 0,
            'data_lancamento' => $dataLancamento,
            'explicit' => $dados['explicit'] ?? false,
            'preview_url' => $dados['preview_url'],
            'isrc' => $dados['isrc']
        ]);

        Log::info("Nova música criada: {$novaMusica->titulo}");
        return $novaMusica;
    }

    private function associarGeneros(Musica $musica, \Illuminate\Support\Collection $generos): void
    {
        $generoIds = $generos->pluck('id')->toArray();
        $musica->generos()->syncWithoutDetaching($generoIds);
    }

    private function associarGenerosAoArtista(Artista $artista, \Illuminate\Support\Collection $generos): void
    {
        $generoIds = $generos->pluck('id')->toArray();
        $artista->generos()->syncWithoutDetaching($generoIds);
    }

    // app/Services/MusicaImporter.php

    private function adicionarAoMediaLibrary(Musica $musica, string $arquivoMp3, array $dados): void
    {
        // Renomear arquivo para usar o token
        $novoNome = $musica->token . '.mp3';
        $novoCaminho = dirname($arquivoMp3) . '/' . $novoNome;
        
        if (rename($arquivoMp3, $novoCaminho)) {
            $arquivoMp3 = $novoCaminho;
            Log::info("Arquivo renomeado para: {$novoNome}");
        }

        // Adicionar áudio
        $mediaItem = $musica->addMedia($arquivoMp3)
            ->preservingOriginal()
            ->usingName($dados['titulo'])
            ->usingFileName($novoNome)
            ->toMediaCollection('audio');

        Log::info("Áudio adicionado ao MediaLibrary: " . $mediaItem->getPath());

        // DELETAR O ARQUIVO ORIGINAL APÓS MOVER
        if (file_exists($arquivoMp3)) {
            unlink($arquivoMp3);
            Log::info("Arquivo original deletado: {$arquivoMp3}");
        }

        // Baixar e adicionar capa se disponível
        if (!empty($dados['capa_url'])) {
            try {
                $capaResponse = Http::timeout(30)->get($dados['capa_url']);
                if ($capaResponse->successful()) {
                    $extensao = $this->detectarExtensaoImagem($dados['capa_url']);
                    $nomeCapaTemp = tempnam(sys_get_temp_dir(), 'capa_') . $extensao;
                    file_put_contents($nomeCapaTemp, $capaResponse->body());
                    
                    $nomeCapaFinal = $musica->token . '_capa' . $extensao;
                    
                    $musica->addMedia($nomeCapaTemp)
                        ->usingName($dados['titulo'] . ' - Capa')
                        ->usingFileName($nomeCapaFinal)
                        ->toMediaCollection('capas');
                        
                    unlink($nomeCapaTemp);
                    Log::info("Capa baixada e adicionada com sucesso");
                }
            } catch (\Exception $e) {
                Log::warning("Erro ao baixar capa: " . $e->getMessage());
            }
        }
    }

    private function detectarExtensaoImagem(string $url): string
    {
        $extensoes = ['.jpg', '.jpeg', '.png', '.webp'];
        
        foreach ($extensoes as $ext) {
            if (strpos(strtolower($url), $ext) !== false) {
                return $ext;
            }
        }
        
        return '.jpg'; // Padrão
    }

     /**
     * Obtém token do Spotify com cache e renovação automática
     */
    private function getSpotifyToken(): void
    {
        try {
            // Verificar se já temos um token válido em cache
            $cachedToken = Cache::get('spotify_token');
            $cachedExpires = Cache::get('spotify_token_expires');

            if ($cachedToken && $cachedExpires && Carbon::now()->lt($cachedExpires)) {
                $this->spotifyToken = $cachedToken;
                $this->spotifyTokenExpires = $cachedExpires;
                Log::info("✅ Token do Spotify recuperado do cache");
                return;
            }

            Log::info("🔄 Solicitando novo token do Spotify...");

            $response = Http::timeout(15)
                ->asForm()
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode(
                        config('services.spotify.client_id') . ':' . config('services.spotify.client_secret')
                    )
                ])
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'client_credentials'
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->spotifyToken = $data['access_token'];
                
                // Calcular tempo de expiração (com margem de segurança de 5 minutos)
                $expiresIn = $data['expires_in'] ?? 3600;
                $this->spotifyTokenExpires = Carbon::now()->addSeconds($expiresIn - 300);

                // Armazenar no cache
                Cache::put('spotify_token', $this->spotifyToken, $this->spotifyTokenExpires);
                Cache::put('spotify_token_expires', $this->spotifyTokenExpires, $this->spotifyTokenExpires);

                Log::info("✅ Token do Spotify obtido com sucesso. Expira em: " . $this->spotifyTokenExpires->format('Y-m-d H:i:s'));
            } else {
                Log::error("❌ Erro ao obter token do Spotify: " . $response->status() . " - " . $response->body());
                $this->spotifyToken = null;
            }

        } catch (\Exception $e) {
            Log::error("❌ Exceção ao obter token do Spotify: " . $e->getMessage());
            $this->spotifyToken = null;
        }
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->pastaDestino)) {
            mkdir($this->pastaDestino, 0755, true);
        }
    }

    private function limparTexto(string $texto): string
    {
        // Remove caracteres especiais e normaliza
        $texto = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $texto);
        $texto = preg_replace('/\s+/', ' ', $texto);
        return trim($texto);
    }

    /**
     * Verifica status da conexão com Spotify
     */
    public function checkSpotifyStatus(): array
    {
        $status = [
            'credentials_configured' => $this->validateSpotifyCredentials(),
            'token_valid' => false,
            'token_expires' => null,
            'api_accessible' => false
        ];

        if ($status['credentials_configured']) {
            $status['token_valid'] = $this->ensureValidSpotifyToken();
            $status['token_expires'] = $this->spotifyTokenExpires?->format('Y-m-d H:i:s');

            // Testar acesso à API
            if ($status['token_valid']) {
                try {
                    $response = Http::withToken($this->spotifyToken)
                        ->timeout(5)
                        ->get("https://api.spotify.com/v1/search", [
                            'q' => 'test',
                            'type' => 'track',
                            'limit' => 1
                        ]);
                    
                    $status['api_accessible'] = $response->successful();
                } catch (\Exception $e) {
                    $status['api_accessible'] = false;
                }
            }
        }

        return $status;
    }

     /**
     * Verifica se o token está válido e renova se necessário
     */
    private function ensureValidSpotifyToken(): bool
    {
        if (!$this->spotifyToken || 
            ($this->spotifyTokenExpires && Carbon::now()->gte($this->spotifyTokenExpires))) {
            
            Log::info("🔄 Token expirado ou inexistente, renovando...");
            $this->getSpotifyToken();
        }

        return !empty($this->spotifyToken);
    }




    /**
     * Método principal de busca com todas as estratégias
     */
    public function buscarDadosSpotify(string $artista, string $titulo): ?array
    {
        Log::info("🎯 Iniciando busca completa no Spotify");
        
        // Se artista é "desconhecido", usar estratégia de busca ampla
        if ($artista === 'Artista Desconhecido' || empty(trim($artista))) {
            Log::info("🔍 Usando busca ampla (artista desconhecido)");
            return $this->buscarSpotifyPorTituloCompleto($titulo);
        }
        
        // Tentativa 1: Busca normal
        Log::info("🔍 Tentativa 1: Busca normal");
        $resultado = $this->buscarNoSpotify($artista, $titulo); // ✅ CHAMA O MÉTODO ORIGINAL
        if ($resultado) return $resultado;
        
        // Tentativa 2: Estratégias alternativas
        Log::info("🔍 Tentativa 2: Estratégias alternativas");
        $resultado = $this->buscarSpotifyAlternativo($artista, $titulo);
        if ($resultado) return $resultado;
        
        // Tentativa 3: Busca ampla com título completo
        Log::info("🔍 Tentativa 3: Busca ampla");
        $resultado = $this->buscarSpotifyPorTituloCompleto($titulo);
        if ($resultado) return $resultado;
        
        // Tentativa 4: Busca apenas por artista
        Log::info("🔍 Tentativa 4: Busca apenas por artista");
        $resultado = $this->buscarSpotifyPorArtista($artista);
        if ($resultado) return $resultado;

        Log::info("❌ Nenhuma estratégia encontrou resultados");
        return null;
    }


    /**
     * Busca ampla quando só temos o título completo
     */
    private function buscarSpotifyPorTituloCompleto(string $tituloCompleto): ?array
    {
        Log::info("🔍 Busca ampla no Spotify com: '{$tituloCompleto}'");
        
        if (!$this->ensureValidSpotifyToken()) {
            return null;
        }

        // Limpar o título para busca
        $tituloLimpo = $this->limparTituloParaBusca($tituloCompleto);
        
        // Estratégias de busca ampla
        $queries = [
            // Busca exata com aspas
            "\"{$tituloLimpo}\"",
            
            // Busca por palavras-chave principais
            $this->extrairPalavrasChave($tituloLimpo),
            
            // Busca simples
            $tituloLimpo,
            
            // Busca removendo palavras comuns
            $this->removerPalavrasComuns($tituloLimpo),
            
            // Busca apenas com primeiras 2 palavras
            $this->extrairPrimeiraspalavras($tituloLimpo, 2)
        ];

        foreach ($queries as $index => $query) {
            if (empty(trim($query))) continue;
            
            $queryEncoded = urlencode($query);
            Log::info("🎯 Estratégia ampla " . ($index + 1) . ": '{$query}'");
            
            try {
                $response = Http::withToken($this->spotifyToken)
                    ->timeout(15)
                    ->get("https://api.spotify.com/v1/search", [
                        'q' => $queryEncoded,
                        'type' => 'track',
                        'limit' => 20, // Mais resultados para busca ampla
                        'market' => 'BR'
                    ]);

                if ($response->successful() && !empty($response->json('tracks.items'))) {
                    $tracks = $response->json('tracks.items');
                    
                    // Para busca ampla, usar threshold menor (40%)
                    $bestMatch = $this->findBestTrackMatchFlexible($tracks, $tituloCompleto);
                    
                    if ($bestMatch) {
                        Log::info("✅ Match encontrado com busca ampla " . ($index + 1));
                        return $this->formatSpotifyTrackData($bestMatch);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Erro na busca ampla " . ($index + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        return null;
    }



    /**
     * Extrai palavras-chave principais
     */
    private function extrairPalavrasChave(string $texto): string
    {
        $palavras = explode(' ', $texto);
        $palavras = array_filter($palavras, function($palavra) {
            return strlen(trim($palavra)) > 2; // Palavras com mais de 2 caracteres
        });
        
        // Pegar as primeiras 3-4 palavras mais significativas
        $palavrasChave = array_slice($palavras, 0, min(4, count($palavras)));
        
        return implode(' ', $palavrasChave);
    }

    /**
     * Remove palavras muito comuns
     */
    private function removerPalavrasComuns(string $texto): string
    {
        $palavrasComuns = [
            'official', 'video', 'music', 'audio', 'lyrics', 'hd', 'hq',
            'full', 'complete', 'version', 'original', 'remix', 'cover',
            'live', 'performance', 'acoustic', 'instrumental', 'videoclipe',
            'oficial', 'letra', 'clipe', 'versão'
        ];
        
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $palavrasComuns)) . ')\b/i';
        $textoLimpo = preg_replace($pattern, '', $texto);
        
        // Limpar espaços extras
        $textoLimpo = preg_replace('/\s+/', ' ', $textoLimpo);
        
        return trim($textoLimpo);
    }

    /**
     * Extrai primeiras N palavras
     */
    private function extrairPrimeiraspalavras(string $texto, int $quantidade): string
    {
        $palavras = explode(' ', $texto);
        $palavras = array_filter($palavras, function($palavra) {
            return strlen(trim($palavra)) > 1;
        });
        
        $primeiras = array_slice($palavras, 0, $quantidade);
        return implode(' ', $primeiras);
    }
    /**
     * Limpa título removendo elementos que atrapalham a busca
     */
    private function limparTituloParaBusca(string $titulo): string
    {
        // Remover aspas extras
        $titulo = trim($titulo, '"\'');
        
        // Remover conteúdo entre parênteses e colchetes
        $titulo = preg_replace('/\([^)]*\)/', '', $titulo);
        $titulo = preg_replace('/\[[^\]]*\]/', '', $titulo);
        
        // Remover palavras específicas do YouTube
        $palavrasRemover = [
            'official', 'video', 'videoclipe', 'oficial', 'lyrics', 'letra',
            'hd', 'hq', 'full', 'complete', 'version', 'versão', 'clipe'
        ];
        
        foreach ($palavrasRemover as $palavra) {
            $titulo = preg_replace('/\b' . preg_quote($palavra, '/') . '\b/i', '', $titulo);
        }
        
        // Limpar espaços extras
        $titulo = preg_replace('/\s+/', ' ', $titulo);
        
        return trim($titulo);
    }

    /**
     * Busca apenas por artista (para encontrar músicas populares)
     */
    private function buscarSpotifyPorArtista(string $artista): ?array
    {
        Log::info("🔍 Busca por artista: '{$artista}'");
        
        if (!$this->ensureValidSpotifyToken()) {
            return null;
        }

        try {
            $query = urlencode("artist:\"{$artista}\"");
            
            $response = Http::withToken($this->spotifyToken)
                ->timeout(15)
                ->get("https://api.spotify.com/v1/search", [
                    'q' => $query,
                    'type' => 'track',
                    'limit' => 10,
                    'market' => 'BR'
                ]);

            if ($response->successful() && !empty($response->json('tracks.items'))) {
                $tracks = $response->json('tracks.items');
                
                // Retornar a música mais popular do artista
                $trackMaisPopular = collect($tracks)->sortByDesc('popularity')->first();
                
                if ($trackMaisPopular) {
                    Log::info("✅ Encontrada música popular do artista: " . $trackMaisPopular['name']);
                    return $this->formatSpotifyTrackData($trackMaisPopular);
                }
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ Erro na busca por artista: " . $e->getMessage());
        }

        return null;
    }




    /**
     * Match mais flexível para busca ampla
     */
    private function findBestTrackMatchFlexible(array $tracks, string $targetText): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        $targetLower = strtolower($this->limparTituloParaBusca($targetText));

        foreach ($tracks as $track) {
            $trackTitle = strtolower($track['name']);
            $trackArtist = strtolower($track['artists'][0]['name']);
            
            // Calcular diferentes tipos de similaridade
            $scores = [
                $this->calculateStringSimilarity($trackTitle, $targetLower),
                $this->calculateStringSimilarity($trackArtist . ' ' . $trackTitle, $targetLower),
                $this->calculateWordSimilarity($trackTitle, $targetLower),
                $this->calculateContainsSimilarity($trackTitle, $targetLower)
            ];
            
            $score = max($scores);
            
            Log::info("🎵 '{$track['artists'][0]['name']} - {$track['name']}' = " . round($score * 100, 1) . "%");
            
            if ($score > $bestScore && $score >= 0.4) { // Threshold: 40%
                $bestScore = $score;
                $bestMatch = $track;
            }
        }

        Log::info("🎯 Melhor score (busca flexível): " . round($bestScore * 100, 2) . "%");
        return $bestMatch;
    }


    /**
     * Verifica se uma string contém partes da outra
     */
    private function calculateContainsSimilarity(string $str1, string $str2): float
    {
        $shorter = strlen($str1) < strlen($str2) ? $str1 : $str2;
        $longer = strlen($str1) >= strlen($str2) ? $str1 : $str2;
        
        if (strpos($longer, $shorter) !== false) {
            return strlen($shorter) / strlen($longer);
        }
        
        return 0;
    }

    /**
     * Similaridade usando similar_text
     */
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) return 0;
        
        similar_text($str1, $str2, $percent);
        return $percent / 100;
    }

    /**
     * Similaridade baseada em palavras em comum
     */
    private function calculateWordSimilarity(string $str1, string $str2): float
    {
        $words1 = array_filter(explode(' ', $str1));
        $words2 = array_filter(explode(' ', $str2));
        
        if (empty($words1) || empty($words2)) return 0;
        
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        return count($intersection) / count($union);
    }

    /**
     * Busca no Spotify com fallback para termo original
     */
    private function buscarDadosSpotifyComFallback(string $artista, string $titulo, string $termoOriginal): ?array
    {
        Log::info("🎯 Iniciando busca com fallback");
        Log::info("   Artista extraído: '{$artista}'");
        Log::info("   Título extraído: '{$titulo}'");
        Log::info("   Termo original: '{$termoOriginal}'");
        
        // Primeira tentativa: usar dados extraídos do arquivo
        $resultado = $this->buscarDadosSpotify($artista, $titulo);
        
        if ($resultado) {
            $score = $this->calcularScoreConfianca($resultado, $artista, $titulo);
            Log::info("🎯 Score de confiança: " . round($score * 100, 1) . "%");
            
            if ($score >= 0.6) { // 60% de confiança
                Log::info("✅ Match satisfatório encontrado com dados extraídos");
                return $resultado;
            } else {
                Log::info("⚠️ Score baixo ({$score}%), tentando com termo original...");
            }
        } else {
            Log::info("❌ Nenhum resultado com dados extraídos, tentando termo original...");
        }
        
        // Segunda tentativa: usar termo de busca original
        Log::info("🔄 Buscando com termo original: '{$termoOriginal}'");
        $resultadoFallback = $this->buscarSpotifyPorTermoOriginal($termoOriginal);
        
        if ($resultadoFallback) {
            Log::info("✅ Match encontrado com termo original!");
            return $resultadoFallback;
        }
        
        // Se nenhum dos dois funcionou, retornar o primeiro resultado (mesmo com score baixo)
        if ($resultado) {
            Log::info("🤷 Usando resultado original mesmo com score baixo");
            return $resultado;
        }
        
        Log::info("❌ Nenhum resultado encontrado em ambas as tentativas");
        return null;
    }

    /**
     * Busca no Spotify usando o termo de pesquisa original
     */
    private function buscarSpotifyPorTermoOriginal(string $termoOriginal): ?array
    {
        if (!$this->ensureValidSpotifyToken()) {
            return null;
        }

        // Limpar o termo original
        $termoLimpo = $this->limparTermoOriginal($termoOriginal);
        
        // Estratégias de busca com termo original
        $queries = [
            // Busca exata
            "\"{$termoLimpo}\"",
            
            // Busca simples
            $termoLimpo,
            
            // Busca com palavras-chave
            $this->extrairPalavrasChave($termoLimpo),
            
            // Busca removendo palavras comuns
            $this->removerPalavrasComuns($termoLimpo),
            
            // Busca apenas primeiras palavras
            $this->extrairPrimeiraspalavras($termoLimpo, 3)
        ];

        foreach ($queries as $index => $query) {
            if (empty(trim($query))) continue;
            
            $queryEncoded = urlencode($query);
            Log::info("🎯 Termo original - Estratégia " . ($index + 1) . ": '{$query}'");
            
            try {
                $response = Http::withToken($this->spotifyToken)
                    ->timeout(15)
                    ->get("https://api.spotify.com/v1/search", [
                        'q' => $queryEncoded,
                        'type' => 'track',
                        'limit' => 20,
                        'market' => 'BR'
                    ]);

                if ($response->successful() && !empty($response->json('tracks.items'))) {
                    $tracks = $response->json('tracks.items');
                    
                    // Para termo original, usar threshold ainda menor (30%)
                    $bestMatch = $this->findBestTrackMatchParaTermoOriginal($tracks, $termoOriginal);
                    
                    if ($bestMatch) {
                        Log::info("✅ Match encontrado com termo original - Estratégia " . ($index + 1));
                        return $this->formatSpotifyTrackData($bestMatch);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Erro na busca com termo original " . ($index + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Calcula score de confiança do resultado encontrado
     */
    private function calcularScoreConfianca(array $dadosSpotify, string $artistaExtraido, string $tituloExtraido): float
    {
        $artistaSpotify = strtolower($dadosSpotify['artista'] ?? '');
        $tituloSpotify = strtolower($dadosSpotify['titulo'] ?? '');
        $artistaExtraidoLower = strtolower($artistaExtraido);
        $tituloExtraidoLower = strtolower($tituloExtraido);
        
        // Calcular similaridades
        $scoreArtista = $this->calculateStringSimilarity($artistaSpotify, $artistaExtraidoLower);
        $scoreTitulo = $this->calculateStringSimilarity($tituloSpotify, $tituloExtraidoLower);
        
        // Peso: título 70%, artista 30% (título é mais importante)
        $scoreFinal = ($scoreTitulo * 0.7) + ($scoreArtista * 0.3);
        
        Log::info("📊 Scores de confiança:");
        Log::info("   Artista: '{$artistaExtraido}' vs '{$dadosSpotify['artista']}' = " . round($scoreArtista * 100, 1) . "%");
        Log::info("   Título: '{$tituloExtraido}' vs '{$dadosSpotify['titulo']}' = " . round($scoreTitulo * 100, 1) . "%");
        Log::info("   Score final: " . round($scoreFinal * 100, 1) . "%");
        
        return $scoreFinal;
    }

    /**
     * Limpa o termo de busca original para uso no Spotify
     */
    private function limparTermoOriginal(string $termo): string
    {
        // Remover caracteres especiais de busca do YouTube
        $termo = preg_replace('/[<>:"\/\|?*]/', '', $termo);
        
        // Remover palavras específicas do YouTube que não ajudam
        $palavrasRemover = [
            'youtube', 'video', 'official', 'lyrics', 'audio', 'music',
            'full', 'hd', 'hq', 'download', 'free', 'mp3', 'mp4'
        ];
        
        foreach ($palavrasRemover as $palavra) {
            $termo = preg_replace('/\b' . preg_quote($palavra, '/') . '\b/i', '', $termo);
        }
        
        // Limpar espaços extras
        $termo = preg_replace('/\s+/', ' ', $termo);
        
        return trim($termo);
    }

    /**
     * Match mais flexível para busca com termo original
     */
    private function findBestTrackMatchParaTermoOriginal(array $tracks, string $termoOriginal): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        $termoLimpo = strtolower($this->limparTermoOriginal($termoOriginal));

        foreach ($tracks as $track) {
            $trackTitle = strtolower($track['name']);
            $trackArtist = strtolower($track['artists'][0]['name']);
            $trackCombined = $trackArtist . ' ' . $trackTitle;
            
            // Múltiplas comparações
            $scores = [
                $this->calculateStringSimilarity($trackTitle, $termoLimpo),
                $this->calculateStringSimilarity($trackCombined, $termoLimpo),
                $this->calculateWordSimilarity($trackTitle, $termoLimpo),
                $this->calculateWordSimilarity($trackCombined, $termoLimpo),
                $this->calculateContainsSimilarity($trackTitle, $termoLimpo),
                $this->calculateContainsSimilarity($trackCombined, $termoLimpo)
            ];
            
            $score = max($scores);
            
            Log::info("🎵 '{$track['artists'][0]['name']} - {$track['name']}' = " . round($score * 100, 1) . "%");
            
            if ($score > $bestScore && $score >= 0.3) { // Threshold: 30% para termo original
                $bestScore = $score;
                $bestMatch = $track;
            }
        }

        Log::info("🎯 Melhor score (termo original): " . round($bestScore * 100, 2) . "%");
        return $bestMatch;
    }
}