<x-app-layout>
    <x-slot name="header">Help center</x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card class="mb-6 rounded-card soft-shadow">
            <div class="text-center py-4">
                <h2 class="text-xl font-semibold text-gray-800">How can we help?</h2>
                <p class="text-sm text-gray-500 mt-1">Short, simple guides for every feature.</p>
                <form method="GET" class="mt-4 max-w-lg mx-auto">
                    <div class="relative">
                        <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                        <input name="q" value="{{ $q }}" placeholder="Search help… (e.g. import, warm up, opt out)"
                               class="w-full pl-10 pr-3 py-2.5 rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    </div>
                </form>
            </div>
        </x-card>

        {{-- AI helper --}}
        <x-card title="✨ Ask the AI helper" subtitle="Get a quick answer about how to do something in Eagle." class="mb-6" x-data="helpAi()">
            <div class="flex gap-2">
                <input x-model="q" @keydown.enter="ask()" placeholder="e.g. How do I send to only my VIP contacts?"
                       class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                <x-btn type="button" variant="gradient" x-on:click="ask()" x-bind:disabled="loading">
                    <span x-show="!loading">Ask</span><span x-show="loading" x-cloak>…</span>
                </x-btn>
            </div>
            @unless ($aiReady)
                <p class="text-xs text-amber-600 mt-2">Add an AI key in <a href="{{ route('settings.edit') }}" class="underline">Settings</a> to enable this.</p>
            @endunless
            <div x-show="answer" x-cloak class="mt-3 rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm text-gray-700 whitespace-pre-line" x-text="answer"></div>
            <div x-show="error" x-cloak class="mt-3 text-sm text-red-600" x-text="error"></div>
        </x-card>

        {{-- Article grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @forelse ($articles as $key => $a)
                <a href="{{ route('help.show', $key) }}" class="block bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md hover:border-brand/40 transition">
                    <div class="flex items-start gap-3">
                        <span class="grid place-items-center w-10 h-10 rounded-lg bg-brand/10 text-brand shrink-0">
                            <x-nav-icon :icon="$a['icon'] ?? 'doc'" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-800">{{ $a['title'] }}</h3>
                            <p class="text-sm text-gray-500 mt-0.5">{{ $a['summary'] }}</p>
                        </div>
                    </div>
                </a>
            @empty
                <div class="sm:col-span-2 text-center text-gray-500 py-10">No help articles match "{{ $q }}". <a href="{{ route('help.index') }}" class="text-brand">Clear search</a>.</div>
            @endforelse
        </div>
    </div>

    @push('scripts')
    <script>
        function helpAi() {
            return {
                q: '', answer: '', error: '', loading: false,
                ask() {
                    if (! this.q.trim()) return;
                    this.loading = true; this.answer = ''; this.error = '';
                    fetch('{{ route('help.ask') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({ question: this.q }),
                    })
                    .then(r => r.json())
                    .then(d => { this.loading = false; d.ok ? (this.answer = d.answer) : (this.error = d.message || 'Failed.'); })
                    .catch(() => { this.loading = false; this.error = 'Could not reach the server.'; });
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
