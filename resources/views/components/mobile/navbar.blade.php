<div
    x-data="{ open: true }"
    class="fixed top-0 left-0 z-50 h-screen bg-gray-800 text-white flex flex-col transition-all duration-300"
    :class="open ? 'w-60' : 'w-[70px]'"
>
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700">
        <span x-show="open" x-cloak class="text-xl font-bold">Logo</span>
        <button @click="open = !open" class="text-white bg-gray-700 hover:bg-gray-600 px-2 py-1 rounded">
            ☰
        </button>
    </div>

    <nav class="flex-grow px-2 py-4 space-y-1">
        <a href="#" class="flex items-center space-x-2 p-2 rounded hover:bg-gray-700">
            <!-- SVG Dashboard -->
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v18H3V3z" />
            </svg>
            <span x-show="open" x-cloak class="whitespace-nowrap">Dashboard</span>
        </a>
        <!-- Adicione mais links aqui -->
    </nav>
</div>
