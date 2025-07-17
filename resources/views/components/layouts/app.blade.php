@props([
    'title' => '',
    'description' => '',
    'meta_title' => null,
    'scrollSmooth' => false,
    'header' => false,
    'navbar' => false,
    'footer' => true,
])

<!DOCTYPE html>
<html lang="pt-BR" @if($scrollSmooth) class="scroll-smooth" @endif>
<head>
    <meta charset="UTF-8">
    <title>{{ $meta_title ?? ($title ? "$title - Neonfy" : 'Neonfy') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @if($description)
        <meta name="description" content="{{ $description }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

    {{-- Tailwind + Alpine + Assets --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body x-data x-init="
  if(!$store.sidebar) {
    $store.sidebar = Alpine.reactive({ open: true });
  }
" {{ $attributes->merge(['class' => 'bg-[#0D0D0D] text-white font-sans min-h-screen flex flex-col']) }}>

    {{-- NAVBAR opcional --}}
    @if($navbar)
        <div class="sm:hidden">
            <x-mobile.navbar />
        </div>
        <div class="hidden sm:block">
            <x-desktop.navbar />
        </div>
    @endif
    <div class="flex flex-col min-h-screen transition-all duration-300"
     :style="$store.sidebar.open ? 'margin-left: 15rem;' : 'margin-left: 4.375rem;'">

        {{-- HEADER opcional --}}
        @if($header)
            <div class="sm:hidden">
                <x-mobile.header />
            </div>
            <div class="hidden sm:block">
                <x-desktop.header />
            </div>
        @endif

        {{-- CONTEÚDO --}}
        <main class="flex-1 p-6 overflow-y-auto">
            {{ $slot }}
        </main>

        {{-- FOOTER opcional --}}
        @if($footer)
            <div class="sm:hidden">
                <x-mobile.footer />
            </div>
            <div class="hidden sm:block">
                <x-desktop.footer />
            </div>
        @endif
    </div>

    {{-- Extra JS --}}
    @stack('scripts')
</body>
</html>

