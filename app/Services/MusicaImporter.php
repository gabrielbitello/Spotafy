<?php
namespace App\Services;

use App\Models\Musica;
use App\Models\Artista;
use App\Models\Album;
use App\Models\Genero;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Carbon\Carbon;

class MusicaImporter
{
   

    public function __construct()
    {
        
    }

    
     

   

    private function verificarMusicaExistente(string $query): ?Musica
    {
        return Musica::where('titulo', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('artista', function($q) use ($query) {
                        $q->where('nome', 'LIKE', '%' . $query . '%');
                    })
                    ->first();
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

    

    // app/Services/MusicaImporter.php
    

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