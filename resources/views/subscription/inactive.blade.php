<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscription · {{ config('app.name', 'Eagle') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-100">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-md border border-gray-100 p-8 text-center">
            <div class="mx-auto w-14 h-14 grid place-items-center rounded-full bg-amber-100 text-amber-600 mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h1 class="text-lg font-semibold text-gray-800">
                {{ $reason === 'suspended' ? 'Your account is suspended' : 'Your subscription has expired' }}
            </h1>
            <p class="mt-2 text-sm text-gray-500">
                {{ $reason === 'suspended'
                    ? 'Access to this workspace has been paused. Please contact your administrator to restore it.'
                    : 'Your plan ended'.($tenant->expires_at ? ' on '.$tenant->expires_at->format('M j, Y') : '').'. Please contact your administrator to renew.' }}
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-6">
                @csrf
                <button class="w-full px-4 py-2.5 rounded-lg bg-gray-800 text-white text-sm font-medium hover:bg-gray-900">Log out</button>
            </form>
        </div>
    </div>
</body>
</html>
