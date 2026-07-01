<x-app-layout>
    <x-slot name="header">{{ $campaign->name }}</x-slot>

    @if ($campaign->status === 'paused')
        <div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-5 py-4 text-sm flex items-center gap-2 flex-wrap">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><strong>Circuit breaker tripped.</strong> Sending paused — the device may have disconnected. Reconnect it on the Devices page, then press Resume. No recipients were lost.</span>
        </div>
    @endif

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('campaigns.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; All campaigns</a>
        <x-campaign-status :status="$campaign->status" />
        <div class="ml-auto flex items-center gap-2">
            @if (in_array($campaign->status, ['draft', 'scheduled', 'paused']))
                <form method="POST" action="{{ route('campaigns.launch', $campaign) }}">
                    @csrf
                    <x-btn type="submit" variant="primary">{{ $campaign->status === 'paused' ? 'Resume' : 'Launch now' }}</x-btn>
                </form>
            @endif
            @if ($campaign->status === 'sending')
                <form method="POST" action="{{ route('campaigns.pause', $campaign) }}">
                    @csrf
                    <x-btn type="submit" variant="secondary">Pause</x-btn>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2">
            <x-card title="Progress">
                <div data-progress>
                    <div class="flex items-center justify-between text-sm mb-2">
                        <span class="text-gray-500">Sent <span data-sent>{{ $campaign->sent }}</span> of {{ $campaign->total }}</span>
                        <span class="font-medium" data-percent>{{ $campaign->progressPercent() }}%</span>
                    </div>
                    <div class="h-3 rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full bg-green-500 transition-all" data-bar style="width: {{ $campaign->progressPercent() }}%"></div>
                    </div>
                    <div class="grid grid-cols-3 gap-4 mt-5 text-center">
                        <div><p class="text-2xl font-bold text-gray-800">{{ $campaign->total }}</p><p class="text-xs text-gray-500">Recipients</p></div>
                        <div><p class="text-2xl font-bold text-green-600" data-sent-stat>{{ $campaign->sent }}</p><p class="text-xs text-gray-500">Sent</p></div>
                        <div><p class="text-2xl font-bold text-red-500" data-failed-stat>{{ $campaign->failed }}</p><p class="text-xs text-gray-500">Failed</p></div>
                    </div>
                </div>
            </x-card>
        </div>

        <div>
            <x-card title="Details">
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Device</dt><dd class="text-gray-800 truncate">{{ $campaign->instance->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Type</dt><dd class="text-gray-800">{{ ucfirst($campaign->type) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Delay</dt><dd class="text-gray-800">{{ $campaign->min_delay }}–{{ $campaign->max_delay }}s</dd></div>
                    @if ($campaign->scheduled_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Scheduled</dt><dd class="text-gray-800">{{ $campaign->scheduled_at->format('M j, g:i A') }}</dd></div>
                    @endif
                </dl>
                @if ($campaign->type !== 'poll' && $campaign->body)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-1">Message</p>
                        <p class="text-sm text-gray-700 whitespace-pre-line">{{ $campaign->body }}</p>
                    </div>
                @endif
                @if ($campaign->type === 'poll')
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-1">Poll</p>
                        <p class="text-sm font-medium text-gray-800">{{ data_get($campaign->poll, 'question') }}</p>
                        <ul class="text-sm text-gray-600 list-disc list-inside mt-1">
                            @foreach (data_get($campaign->poll, 'options', []) as $opt)<li>{{ $opt }}</li>@endforeach
                        </ul>
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <x-card flush>
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Recipients</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Contact</th>
                        <th class="px-5 py-3 font-medium">Phone</th>
                        <th class="px-5 py-3 font-medium">Sent from</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Sent at</th>
                        <th class="px-5 py-3 font-medium">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recipients as $r)
                        @php
                            $rc = ['pending' => 'gray', 'sent' => 'blue', 'delivered' => 'blue', 'read' => 'green', 'failed' => 'red'][$r->status] ?? 'gray';
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-800 whitespace-nowrap">{{ $r->contact->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">+{{ $r->phone }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $r->instance->name ?? ($campaign->instance->name ?? '—') }}</td>
                            <td class="px-5 py-3"><x-badge :color="$rc">{{ ucfirst($r->status) }}</x-badge></td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $r->sent_at?->format('M j, g:i A') ?? '—' }}</td>
                            <td class="px-5 py-3 text-red-500 text-xs max-w-xs truncate">{{ $r->error }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No recipients.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($recipients->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $recipients->links() }}</div>
        @endif
    </x-card>

    @push('scripts')
    <script>
        @if ($campaign->status === 'sending')
        (function () {
            const timer = setInterval(() => {
                fetch('{{ route('campaigns.progress', $campaign) }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        document.querySelector('[data-percent]').textContent = d.percent + '%';
                        document.querySelector('[data-bar]').style.width = d.percent + '%';
                        document.querySelector('[data-sent]').textContent = d.sent;
                        document.querySelector('[data-sent-stat]').textContent = d.sent;
                        document.querySelector('[data-failed-stat]').textContent = d.failed;
                        if (d.status === 'completed' || d.status === 'paused') { clearInterval(timer); location.reload(); }
                    })
                    .catch(() => {});
            }, 3000);
        })();
        @endif
    </script>
    @endpush
</x-app-layout>
