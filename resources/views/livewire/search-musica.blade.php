<div>
    <h2 class="text-xl mb-4">Resultado para: <b>{{ $pesquisa }}</b></h2>

    @if(count($musicas))
        <ul>
            @foreach($musicas as $musica)
                <li>{{ $musica['titulo'] ?? $musica->titulo ?? $musica['nome'] ?? $musica->nome ?? '-' }} — {{ $musica['banda'] ?? $musica->banda ?? $musica['artista'] ?? $musica->artista ?? '-' }}</li>
            @endforeach
        </ul>
    @elseif($loading)
        <div class="flex items-center flex-col">
            <svg class="animate-spin h-10 w-10 text-blue-500 mb-2" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
            <p>Procurando música... (tentativa {{ $tentativas }}/3)</p>
        </div>
    @elseif($erro)
        <div class="bg-red-100 p-4 rounded text-red-700">
            Nenhuma música foi encontrada após 3 tentativas.
        </div>
    @endif
</div>

<script>
    document.addEventListener('pesquisar-novamente', function () {
        setTimeout(function(){
            window.livewire.emit('pesquisarMusica');
        }, 5000);
    });
</script>