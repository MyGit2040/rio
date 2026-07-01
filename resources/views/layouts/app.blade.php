<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $tenant = auth()->user()?->tenant;
    $brand  = data_get($tenant?->settings, 'accent_color', '#8b5cf6');
    $logo   = data_get($tenant?->settings, 'logo_path');
    $brandName = data_get($tenant?->settings, 'brand_name') ?: config('app.name', 'Eagle');
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $brandName }}</title>

    @if ($logo)
        <link rel="icon" href="{{ \Illuminate\Support\Facades\Storage::url($logo) }}">
        <link rel="apple-touch-icon" href="{{ \Illuminate\Support\Facades\Storage::url($logo) }}">
    @endif

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Brand theming: one CSS variable drives accent across the app. --}}
    <style>
        :root { --brand: {{ $brand }}; }
        .bg-brand { background-color: var(--brand); }
        .text-brand { color: var(--brand); }
        .border-brand { border-color: var(--brand); }
        .bg-brand:hover { filter: brightness(.93); }
        .sidebar-active { background: color-mix(in srgb, var(--brand) 12%, white); color: var(--brand); }
        .banner-brand { background-image: linear-gradient(110deg, var(--brand), #4338ca 85%); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-slate-50 text-gray-800">
<div x-data="{ sidebarOpen: false }" class="min-h-screen lg:flex">

    <x-sidebar :logo="$logo" :brand-name="$brandName" />

    {{-- Backdrop on mobile --}}
    <div x-show="sidebarOpen" @click="sidebarOpen = false" x-cloak class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

    {{-- Main column --}}
    <div class="flex-1 min-w-0 flex flex-col">
        <header class="sticky top-0 z-20 bg-white border-b border-gray-200">
            <div class="flex items-center gap-3 h-16 px-4 sm:px-6">
                <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-lg font-semibold text-gray-800 truncate">{{ $header ?? 'Dashboard' }}</h1>

                <div class="ml-auto flex items-center gap-4">
                    <span class="hidden sm:block text-sm text-gray-500">{{ $tenant->name ?? '' }}</span>
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="grid place-items-center w-9 h-9 rounded-full bg-gray-200 text-gray-700 font-semibold">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-100 py-1 text-sm">
                            <div class="px-4 py-2 text-gray-500 border-b">{{ auth()->user()->email }}</div>
                            <a href="{{ route('profile.edit') }}" class="block px-4 py-2 hover:bg-gray-50">Profile</a>
                            <a href="{{ route('settings.edit') }}" class="block px-4 py-2 hover:bg-gray-50">Settings</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf
                                <button type="submit" class="w-full text-left px-4 py-2 text-red-600 hover:bg-gray-50">Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6 max-w-7xl w-full mx-auto">
            @if (session('success'))
                <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
