<div
    x-data
    class="fixed top-0 left-0 z-50 h-screen bg-[#111] px-2 border-r border-[#222] text-white flex flex-col transition-all duration-300"
    :class="$store.sidebar.open ? 'w-60' : 'w-[66px]'"
>
    <div class="flex items-center justify-between px-2 py-3 border-b border-gray-700">
        <span x-show="$store.sidebar.open" x-cloak class="text-xl font-bold">Logo</span>
        <button @click="$store.sidebar.open = !$store.sidebar.open" class="text-white hover:bg-gray-600 px-2 py-1 rounded">
            <span class="w-5 h-5 flex-shrink-0 inline-block align-middle">
                {!! file_get_contents(public_path('build/svg/menu-hamburguer.svg')) !!}
            </span>
        </button>
    </div>

    <nav class="flex-grow px-2 py-4 space-y-1">
        <div 
           class="flex items-center p-1 rounded hover:bg-gray-700 w-[94%]"
           :class="$store.sidebar.open ? 'justify-start space-x-2' : 'justify-center'"
           x-data="{
                valor: '',
                anterior: '',
                slugify(texto) {
                    return texto.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '').replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
                },
                deslugify(slug) {
                    return slug.replace(/-/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '').replace(/\b\w/g, l => l.toUpperCase());
                },
                init() {
                    const path = window.location.pathname;
                    if (path.startsWith('/search/')) {
                        const slug = decodeURIComponent(path.replace('/search/', ''));
                        const texto = this.deslugify(slug);
                        this.valor = texto;
                        this.anterior = texto;
                    }
                },
                pesquisar() {
                    if (this.valor.trim() && this.valor !== this.anterior) {
                        const slug = this.slugify(this.valor);
                        window.location.href = `/search/${slug}`;
                        this.anterior = this.valor;
                    }
                }
           }"
           x-init="init()"
        >
            <label
                @click="
                    if (!$store.sidebar.open) {
                        $store.sidebar.open = true;
                        $nextTick(() => { document.getElementById('pesquisar').focus(); });
                    }
                "
                class="w-5 h-5 flex-shrink-0 inline-block align-middle"
                for="pesquisar"
            >
                {!! file_get_contents(public_path('build/svg/procurar.svg')) !!}
            </label> 
            <input x-show="$store.sidebar.open" x-cloak class="whitespace-nowrap bg-transparent w-full focus:outline-none px-1 border-b border-gray-500 focus:border-blue-400 transition-colors" type="text" id="pesquisar" name="pesquisar" placeholder="Pesquisar..."
                x-model="valor"
                @keydown.enter.prevent="pesquisar()"
                @blur="pesquisar()"
            />
        </div>
        <a href="#" 
           class="flex items-center p-1 rounded hover:bg-gray-700 w-[94%]"
           :class="$store.sidebar.open ? 'justify-start space-x-2' : 'justify-center'"
        >
            <span class="w-5 h-5 flex-shrink-0 inline-block align-middle">
                {!! file_get_contents(public_path('build/svg/casa.svg')) !!}
            </span>
            <span x-show="$store.sidebar.open" x-cloak class="whitespace-nowrap">Dashboard</span>
        </a>
        <!-- outros links -->
    </nav>
</div>
