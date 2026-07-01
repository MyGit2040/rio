<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php($brandLogo = \App\Support\Branding::logoUrl())
        <title>{{ config('app.name', 'Eagle') }}</title>

        @if ($brandLogo)
            <link rel="icon" href="{{ $brandLogo }}">
            <link rel="apple-touch-icon" href="{{ $brandLogo }}">
        @endif

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div>
                <a href="/">
                    @if ($brandLogo)
                        <img src="{{ $brandLogo }}" alt="{{ config('app.name', 'Eagle') }}" class="h-16 w-auto max-w-[220px] object-contain">
                    @else
                        <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                    @endif
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-5 bg-white overflow-hidden rounded-card soft-shadow">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
