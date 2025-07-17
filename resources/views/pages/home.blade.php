@props([
    'items' => ['bola', 'sapo', 'carro'],
])

<x-layouts.app title="Início" description="Página inicial do Neonfy" :footer="true" :header="false" :navbar="true">
    <section>
        <div 
            x-data="{
                layout: 'cols',
                resize(el) {
                    this.layout = el.offsetWidth >= 1200 ? 'cols' : 'rows';
                }
            }"
            x-init="resize($el); window.addEventListener('resize', () => resize($el))"
            :class="layout === 'cols' 
                ? 'grid grid-cols-4 grid-rows-2 gap-4 items-stretch min-h-[16rem]'
                : 'grid grid-cols-2 grid-rows-4 gap-4 items-stretch min-h-[16rem]'"
        >
            @foreach($items as $item)
                <div class="bg-[#1a1a1a] flex flex-row p-4 rounded-xl hover:scale-105 transition shadow hover:shadow-[0_0_24px_0_#39FF14] h-full items-center gap-4 min-w-[180px] md:min-w-[220px]">
                    <div class="w-20 h-20 flex-shrink-0 overflow-hidden flex items-center justify-center">
                        <img src="https://via.placeholder.com/150" class="w-full h-full object-cover rounded" />
                    </div>
                    <h3 class="text-[#39FF14] font-semibold flex-1">
                        {{ $item }}
                    </h3>
                </div>
            @endforeach
        </div>
    </section>
</x-layouts.app>
