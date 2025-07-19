<x-layouts.app title="Início" description="Página inicial do Neonfy" :footer="true" :header="false" :navbar="true">
    <section>
        @livewire('search-musica', ['pesquisa' => $pesquisa ?? null])
    </section>
</x-layouts.app>
