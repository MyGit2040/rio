@props(['header' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin · {{ config('app.name', 'Eagle') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --brand: #7c3aed; }
        .bg-brand { background-color: var(--brand); }
        .text-brand { color: var(--brand); }
        .border-brand { border-color: var(--brand); }
        .bg-brand:hover { filter: brightness(.93); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-slate-100 text-gray-800">
<div class="min-h-screen">
    <header class="bg-gray-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-6">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 font-bold text-lg">
                <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand">⚡</span>
                {{ config('app.name', 'Eagle') }} <span class="text-xs font-normal text-gray-400">Admin</span>
            </a>
            <nav class="hidden sm:flex items-center gap-1 text-sm">
                <a href="{{ route('admin.dashboard') }}" @class(['px-3 py-2 rounded-lg hover:bg-white/10', 'bg-white/10' => request()->routeIs('admin.dashboard')])>Dashboard</a>
                <a href="{{ route('admin.workspaces.index') }}" @class(['px-3 py-2 rounded-lg hover:bg-white/10', 'bg-white/10' => request()->routeIs('admin.workspaces.*')])>Workspaces</a>
                <a href="{{ route('admin.plans.index') }}" @class(['px-3 py-2 rounded-lg hover:bg-white/10', 'bg-white/10' => request()->routeIs('admin.plans.*')])>Plans</a>
            </nav>
            <div class="ml-auto flex items-center gap-3 text-sm">
                <a href="{{ route('dashboard') }}" class="text-gray-300 hover:text-white">Back to app →</a>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        @if ($header)<h1 class="text-xl font-semibold text-gray-800 mb-4">{{ $header }}</h1>@endif

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
@stack('scripts')
</body>
</html>
