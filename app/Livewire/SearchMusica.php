<?php

namespace App\Livewire;

use Livewire\Component;

use App\Services\Music\Importer\MusicImporterCore;
use App\Models\Musica;
use App\Models\Artista;
use Cocur\Slugify\Slugify;
use Illuminate\Support\Facades\Log;

class SearchMusica extends Component
{
    public $pesquisa;
    public $musicas = [];
    public $tentativas = 0;
    public $loading = false;
    public $erro = false;
    public $download = false;

    protected $queryString = ['pesquisa', 'download'];

    public function mount($pesquisa, $download = false)
    {
        $this->pesquisa = $pesquisa;
        // Só dispara download se o parâmetro estiver presente na URL
        $this->download = array_key_exists('download', $_GET);
        $this->pesquisarMusicaNoBanco($pesquisa);
    }

    function desfazerSlug($slug)
    {
        $frase = str_replace('-', ' ', $slug);

        $frase = ucwords($frase);
        return $frase;
    }

    public function fuzzywuzzy_ratio($a, $b)
    {
        similar_text($a, $b, $percent);
        return $percent;
    }



public function pesquisarMusicaNoBanco($slug)
{
    Log::info('Pesquisa recebida', ['pesquisa' => $slug, 'download' => $this->download]);

    // Converter slug em texto formatado
    $frase = ucwords(str_replace('-', ' ', $slug));
    Log::info('Frase convertida do slug', ['fraseOriginal' => $frase]);

    // Buscar todas as músicas com artistas (join para trazer nome do artista)
    $musicas = Musica::with('artista')
        ->where('titulo', 'LIKE', '%'.$frase.'%')
        ->orWhereHas('artista', function($q) use ($frase) {
            $q->where('nome', 'LIKE', '%'.$frase.'%');
        })
        ->get();

    // Busca secundária pelas palavras isoladas da frase
    if ($musicas->isEmpty()) {
        $palavras = explode(' ', $frase);
        $musicas = Musica::with('artista')->where(function($query) use ($palavras) {
            foreach ($palavras as $p) {
                $query->orWhere('titulo', 'LIKE', "%$p%")
                      ->orWhereHas('artista', function($q) use ($p) {
                          $q->where('nome', 'LIKE', "%$p%");
                      });
            }
        })->get();
    }

    Log::info('Músicas localizadas', ['count' => $musicas->count()]);

    // Se ainda não encontrou nada, só aí tenta importar
    if ($musicas->isEmpty()) {
        $this->tentativas = $this->tentativas + 1;
        if ($this->tentativas <= 3) {
            // Importação síncrona (sem queue)
            (new MusicImporterCore())->importar($frase);
            Log::info('Tentando novamente após importação (direto, sem job)', ['tentativa' => $this->tentativas]);
            $this->loading = true;
            // Após importar, tenta buscar de novo
            return $this->pesquisarMusicaNoBanco($slug);
        } else {
            $this->erro = true;
            $this->loading = false;
            Log::warning('Limite de tentativas atingido, abortando loop.');
            return;
        }
    }

    // Se download for true, força importação antes de buscar (agora via job)
    if ($this->download && $this->tentativas < 1) {
        $this->tentativas++;
        // Importação síncrona (sem queue)
        (new MusicImporterCore())->importar($frase);
        Log::info('Forçando importação por parâmetro download (direto, sem job)', ['tentativa' => $this->tentativas]);
        $this->loading = true;
        // Após importar, tenta buscar de novo
        return $this->pesquisarMusicaNoBanco($slug);
    }

    // Fuzzy match: Considerando combinação "título + nome do artista"
    $matches = [];
    $exatos = [];
    $parciais = [];
    $fraseNormalizada = strtolower(trim($frase));
    $palavrasFrase = explode(' ', $fraseNormalizada);

    foreach ($musicas as $musica) {
        $nomeArtista = $musica->artista ? $musica->artista->nome : '';
        $tituloNormalizado = strtolower(trim($musica->titulo));
        $artistaNormalizado = strtolower(trim($nomeArtista));
        $textoMusica = $tituloNormalizado . ' ' . $artistaNormalizado;
        $score = $this->fuzzywuzzy_ratio($textoMusica, $fraseNormalizada);

        // Novo: score separado para título e artista
        $scoreTitulo = $this->fuzzywuzzy_ratio($tituloNormalizado, $fraseNormalizada);
        $scoreArtista = $this->fuzzywuzzy_ratio($artistaNormalizado, $fraseNormalizada);
        $scoreCombinado = ($scoreTitulo + $scoreArtista) / 2;

        $match = [
            'titulo' => $musica->titulo,
            'banda' => $nomeArtista,
            'probabilidade' => $score,
            'score_titulo' => $scoreTitulo,
            'score_artista' => $scoreArtista,
            'score_combinado' => $scoreCombinado
        ];
        // Match exato de título e artista juntos
        if ($tituloNormalizado . ' ' . $artistaNormalizado === $fraseNormalizada) {
            $exatos[] = $match;
        } 
        // Match exato de título ou artista
        else if ($tituloNormalizado === $fraseNormalizada || $artistaNormalizado === $fraseNormalizada) {
            $parciais[] = $match;
        } else {
            $matches[] = $match;
        }
        Log::info('Tentando match', [
            'titulo' => $musica->titulo,
            'banda' => $nomeArtista,
            'score' => $score,
            'score_titulo' => $scoreTitulo,
            'score_artista' => $scoreArtista,
            'score_combinado' => $scoreCombinado
        ]);
    }

    // Se houver matches exatos (título + artista), retorna eles primeiro
    if (!empty($exatos)) {
        Log::info('Top matches exatos (título + artista)', $exatos);
        $this->musicas = $exatos;
        return;
    }
    // Depois matches exatos de título OU artista
    if (!empty($parciais)) {
        usort($parciais, function($a, $b) {
            return $b['score_combinado'] <=> $a['score_combinado'];
        });
        $topParciais = array_slice($parciais, 0, 5);
        // Se tiver menos de 5, completa com fuzzy
        if (count($topParciais) < 5 && !empty($matches)) {
            usort($matches, function($a, $b) {
                return $b['score_combinado'] <=> $a['score_combinado'];
            });
            $faltam = 5 - count($topParciais);
            $topFuzzy = array_slice($matches, 0, $faltam);
            $topParciais = array_merge($topParciais, $topFuzzy);
        }
        Log::info('Top matches exatos (título OU artista) + fuzzy', $topParciais);
        $this->musicas = $topParciais;
        return;
    }

    // Busca por match perfeito (>= 95%)
    $matchPerfeito = null;
    foreach ($matches as $k => $m) {
        if ($m['probabilidade'] >= 95) {
            $matchPerfeito = $m;
            unset($matches[$k]);
            break;
        }
    }

    // NOVO: Se houver qualquer match de artista forte (score_artista >= 60), NÃO dispara o job
    $todosMatches = array_merge($exatos, $parciais, $matches);
    $matchArtistaForte = null;
    foreach ($todosMatches as $m) {
        if (($m['score_artista'] ?? 0) >= 60) {
            $matchArtistaForte = $m;
            break;
        }
    }

    // Se NÃO houver match perfeito nem artista forte, dispara o job
    if (!$matchPerfeito && !$matchArtistaForte && $this->tentativas < 3) {
        $this->tentativas++;
        // Importação síncrona (sem queue)
        (new MusicImporterCore())->importar($frase);
        Log::info('Tentando novamente após importação (busca por match perfeito/artista forte, direto, sem job)', ['tentativa' => $this->tentativas]);
        $this->loading = true;
        // Após importar, tenta buscar de novo
        return $this->pesquisarMusicaNoBanco($slug);
    }

    // Ordena do mais provável para o menos provável (usando probabilidade)
    usort($matches, function($a, $b) {
        return $b['probabilidade'] <=> $a['probabilidade'];
    });

    // Primeiro tenta matches acima de 70% (probabilidade)
    $matchesAboveThreshold = array_filter($matches, function($m) { return $m['probabilidade'] >= 70; });
    $topMatches = array_slice($matchesAboveThreshold, 0, 5);

    $resultados = [];
    if ($matchPerfeito) {
        $resultados[] = $matchPerfeito;
    }
    if (!empty($topMatches)) {
        $resultados = array_merge($resultados, $topMatches);
    } else {
        $fallbackMatches = array_slice($matches, 0, 5);
        $resultados = array_merge($resultados, $fallbackMatches);
    }
    if (!empty($resultados)) {
        Log::info('Resultados finais', $resultados);
        $this->musicas = $resultados;
        return;
    }

    // Após buscar, se download foi forçado, filtra para mostrar apenas músicas novas
    if ($this->download) {
        // Considera como "nova" a música criada nos últimos 2 minutos
        $novas = $musicas->filter(function($m) {
            return $m->created_at && $m->created_at->gt(now()->subMinutes(2));
        });
        if ($novas->count() > 0) {
            Log::info('Exibindo apenas músicas novas importadas', $novas->toArray());
            $this->musicas = $novas->map(function($m) {
                return [
                    'titulo' => $m->titulo,
                    'banda' => $m->artista ? $m->artista->nome : '',
                    'probabilidade' => 100,
                    'score_titulo' => 100,
                    'score_artista' => 100,
                    'score_combinado' => 100
                ];
            })->toArray();
            return;
        } else {
            // Se não houver novas, exibe normalmente todos os resultados
            if (!empty($resultados)) {
                Log::info('Nenhuma música nova importada, exibindo resultados normais', $resultados);
                $this->musicas = $resultados;
                return;
            }
        }
    }
}


    public function render()
    {
        return view('livewire.search-musica');
    }
}



