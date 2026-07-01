<x-app-layout>
    <x-slot name="header">Inbox</x-slot>

    <x-card flush>
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Conversations</h2>
            <form method="GET" class="ml-auto">
                <input name="q" value="{{ request('q') }}" placeholder="Search number…"
                       class="rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
            </form>
        </div>

        <ul class="divide-y divide-gray-100">
            @forelse ($conversations as $m)
                <li>
                    <a href="{{ $m->contact_id ? route('inbox.show', $m->contact_id) : '#' }}"
                       class="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 {{ $m->contact_id ? '' : 'pointer-events-none opacity-70' }}">
                        <span class="grid place-items-center w-10 h-10 rounded-full bg-gray-100 text-gray-600 font-semibold shrink-0">
                            {{ strtoupper(substr($m->contact->name ?? $m->phone, 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-gray-800 truncate">{{ $m->contact->name ?? '+'.$m->phone }}</p>
                                @if ($m->direction === 'in')<x-badge color="green">in</x-badge>@endif
                            </div>
                            <p class="text-sm text-gray-500 truncate">{{ Str::limit($m->body, 80) ?: '—' }}</p>
                        </div>
                        <span class="text-xs text-gray-400 whitespace-nowrap shrink-0">{{ $m->created_at?->diffForHumans(null, true) }}</span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-12 text-center text-gray-500">No conversations yet. Inbound replies from your contacts appear here.</li>
            @endforelse
        </ul>

        @if ($conversations->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $conversations->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
