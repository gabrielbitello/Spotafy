<?php
namespace App\Services\Music\Importer;

use App\Services\Music\Importer\Baixador;
use App\Services\Music\Importer\ImporterServicos;
use App\Services\Music\spotify\SpotifyCore;
use App\Services\Music\spotify\SpotifyPesquisa;
use App\Traits\GeneratesMusicToken;


use App\Models\Musica;
use App\Models\Artista;
use App\Models\Album;
use App\Models\Genero;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MusicImporterCore {
    use GeneratesMusicToken;

    private $baixador;
    private $servicos;
    private $spotifyPesquisa;

    public function __construct()
    {
        $this->servicos = new ImporterServicos();
        $this->spotifyPesquisa = new SpotifyPesquisa();
        $this->pastaDestino = storage_path('app/public/musicas');
        $this->baixador = new Baixador($this->pastaDestino);
        $this->servicos->verificaDiretorioExiste($this->pastaDestino);
        // Remover chamada para initializeSpotifyAuth se não existir
    }

    // app/Services/MusicaImporter.php
    public function importar(string $termoBusca): bool
    {
        try {
            Log::info("🎵 Iniciando importação: {$termoBusca}");

            // 1 - Buscar dados do Spotify
            $dadosSpotify = $this->spotifyPesquisa->buscarDadosSpotify($termoBusca);

            // Extrair dados para o token
            $nome_artista = $dadosSpotify['artista'] ?? 'Desconhecido';
            $nome_musica = $dadosSpotify['titulo'] ?? 'Desconhecido';
            $data_lancamento = $dadosSpotify['data_lancamento'] ?? null;
            // 2 - Gera o token
            $token = self::generateMusicToken($nome_artista, $nome_musica, $data_lancamento);

            // 3 - Verificar se música já existe
            $musicaExistente = Musica::where('token', $token)->first();
            if ($musicaExistente) {
                Log::info("Música já existe com token: {$dados['token']}");
                return true;
            }

            // 4 - Baixar música
            $searchQuery = "{$nome_artista} - {$nome_musica}";
            $arquivoMp3 = $this->baixador->baixarAudioYoutube($searchQuery, $this->pastaDestino);
            if (!$arquivoMp3) {
                throw new \Exception("Não foi possível baixar a música");
            }

            // 5 - Adicionar duração do arquivo
            $duracaoArquivo = $this->servicos->calcularDuracao($arquivoMp3);
            $metadados['duracao'] = $duracaoArquivo;

            // 6 - Mesclar dados
            // Refazer!
            $dados = $this->servicos->mesclarDados($metadados, $dadosSpotify);
            // Buscar ou criar artista
            $artista = Artista::firstOrCreate(['nome' => $dados['artista'] ?? 'Desconhecido']);
            // Buscar ou criar album
            $nomeAlbum = $dados['album'] ?? 'Desconhecido';
            if (strtolower(trim($nomeAlbum)) === 'single') {
                // Sempre cria um novo álbum para singles
                $album = Album::create([
                    'titulo' => $nomeAlbum,
                    'artista_id' => $artista->id
                ]);
            } else {
                // Busca ou cria, evitando duplicatas para o mesmo artista
                $album = Album::firstOrCreate([
                    'titulo' => $nomeAlbum,
                    'artista_id' => $artista->id
                ]);
            }


            // 7 - Criar música
            $musica = $this->criarMusica($dados, $artista, $album, $token);
            $this->adicionarAoMediaLibrary($musica, $arquivoMp3, $dados);

            Log::info("✅ Música importada com sucesso: {$dados['titulo']} - {$dados['artista']}");
            return true;

        } catch (\Exception $e) {
            Log::error("❌ Erro na importação: " . $e->getMessage());
            return false;
        }
    }

     public function importarTodasDoArtista(string $nomeArtista): int
    {
        Log::info("📥 Iniciando importação em lote para artista: {$nomeArtista}");

        $musicas = $this->spotifyPesquisa->buscarMusicasPorArtista($nomeArtista);
        if (empty($musicas)) {
            Log::warning("❌ Nenhuma música encontrada para o artista: {$nomeArtista}");
            return 0;
        }

        $totalImportadas = 0;

        foreach ($musicas as $musica) {
            $termoBusca = "{$musica['artista']} - {$musica['titulo']}";
            try {
                $importado = $this->importar($termoBusca);
                if ($importado) {
                    $totalImportadas++;
                }
            } catch (\Exception $e) {
                Log::error("⚠️ Erro ao importar '{$termoBusca}': " . $e->getMessage());
            }
        }

        Log::info("✅ Importação finalizada para {$nomeArtista}. Total importadas: {$totalImportadas}");
        return $totalImportadas;
    }

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
                    $extensao = $this->servicos->detectarExtensaoImagem($dados['capa_url']);
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

    private function criarMusica(array $dados, Artista $artista, Album $album, string $token): Musica
    {

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
            'isrc' => $dados['isrc'] ?? null
        ]);

        Log::info("Nova música criada: {$novaMusica->titulo}");
        return $novaMusica;
    }
}