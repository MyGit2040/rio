<x-app-layout>
    <x-slot name="header">Help</x-slot>

    <div class="max-w-3xl mx-auto">
        <div class="mb-4"><a href="{{ route('help.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Help center</a></div>

        <x-card class="rounded-card soft-shadow">
            <div class="flex items-start gap-3 mb-5">
                <span class="grid place-items-center w-11 h-11 rounded-lg bg-brand/10 text-brand shrink-0">
                    <x-nav-icon :icon="$article['icon'] ?? 'doc'" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-lg font-semibold text-gray-800">{{ $article['title'] }}</h1>
                    <p class="text-sm text-gray-500">{{ $article['summary'] }}</p>
                </div>
            </div>

            {{-- Steps --}}
            <h2 class="text-sm font-semibold text-gray-700 mb-2">How to do it</h2>
            <ol class="space-y-2 mb-6">
                @foreach ($article['steps'] ?? [] as $i => $step)
                    <li class="flex items-start gap-3">
                        <span class="grid place-items-center w-6 h-6 rounded-full bg-brand text-white text-xs font-bold shrink-0">{{ $i + 1 }}</span>
                        <span class="text-sm text-gray-700">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>

            {{-- Example --}}
            @if (!empty($article['example']))
                <div class="rounded-lg bg-blue-50 border border-blue-200 p-4 mb-6">
                    <p class="text-xs font-semibold text-blue-800 mb-1">Example</p>
                    <p class="text-sm text-blue-900">{{ $article['example'] }}</p>
                </div>
            @endif

            {{-- Tips --}}
            @if (!empty($article['tips']))
                <h2 class="text-sm font-semibold text-gray-700 mb-2">Tips</h2>
                <ul class="space-y-1.5">
                    @foreach ($article['tips'] as $tip)
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <svg class="w-4 h-4 text-green-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>{{ $tip }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        {{-- Related --}}
        <div class="mt-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">More guides</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($related as $rkey => $r)
                    <a href="{{ route('help.show', $rkey) }}" class="flex items-center gap-2 bg-white rounded-lg border border-gray-200 px-4 py-3 text-sm text-gray-700 hover:border-brand/40">
                        <x-nav-icon :icon="$r['icon'] ?? 'doc'" class="w-4 h-4 text-brand" />
                        {{ $r['title'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
