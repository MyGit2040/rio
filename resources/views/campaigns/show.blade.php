<x-app-layout>
    <x-slot name="header">{{ $campaign->name }}</x-slot>

    @if ($campaign->status === 'paused')
        <div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-5 py-4 text-sm flex items-center gap-2 flex-wrap">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><strong>Sending paused</strong> — a WhatsApp number disconnected. Reconnect <strong>any</strong> of this campaign's numbers on the Devices page, then press <strong>Resume</strong>: it continues from exactly where it stopped and spreads the remaining messages across whatever numbers are back online. Nothing is lost.</span>
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
            @if ($campaign->failed > 0)
                <form method="POST" action="{{ route('campaigns.retry', $campaign) }}">
                    @csrf
                    <x-btn type="submit" variant="secondary">Retry {{ $campaign->failed }} failed</x-btn>
                </form>
            @endif
            <x-btn :href="route('campaigns.export', $campaign)" variant="ghost" title="Export CSV" aria-label="Export CSV" class="!px-2.5">
                <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            </x-btn>
        </div>
    </div>

    <form method="POST" action="{{ route('campaigns.test', $campaign) }}" class="mb-6 flex items-end gap-2 flex-wrap">
        @csrf
        <div>
            <x-input-label for="phone" value="Send a test to your own number" />
            <x-text-input id="phone" name="phone" class="block mt-1 w-56" placeholder="971501234567" required />
        </div>
        <x-btn type="submit" variant="secondary">Send test</x-btn>
    </form>

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

    {{-- Responses / engagement (auto-refreshes live) --}}
    <x-card title="Responses" class="mb-6"
            x-data="campaignResponses({{ $campaign->id }}, @js([
                'engagement' => $engagement,
                'poll'       => $pollBreakdown->map(fn ($c, $o) => ['option' => $o, 'count' => $c])->values(),
                'latest'     => $responses->map(fn ($r) => [
                    'icon' => ['poll_response' => '📊', 'button_response' => '🔘'][$r->type] ?? '📩',
                    'who'  => $r->contact->name ?? '+'.$r->phone,
                    'body' => Str::limit($r->body, 120),
                    'ago'  => $r->created_at?->diffForHumans(),
                ]),
            ]))"
            x-init="startPolling()">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div class="text-center"><p class="text-2xl font-bold text-brand" x-text="engagement.replies"></p><p class="text-xs text-gray-500">Replies received</p></div>
            <div class="text-center"><p class="text-2xl font-bold text-green-600" x-text="engagement.poll_answers"></p><p class="text-xs text-gray-500">Poll answers</p></div>
            <div class="text-center"><p class="text-2xl font-bold text-blue-600" x-text="engagement.button_clicks"></p><p class="text-xs text-gray-500">Button clicks</p></div>
        </div>

        <template x-if="breakdown.length">
            <div class="mb-4">
                <p class="text-xs font-semibold text-gray-600 mb-2">Poll answer breakdown</p>
                <div class="space-y-2">
                    <template x-for="(row, i) in breakdown" :key="i">
                        <div>
                            <div class="flex justify-between text-sm mb-0.5"><span class="text-gray-700 truncate" x-text="row.option"></span><span class="text-gray-500 shrink-0 ml-2" x-text="row.count"></span></div>
                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-green-500 transition-all" :style="`width:${pollPercent(row.count)}%`"></div></div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="latest.length">
            <div>
                <p class="text-xs font-semibold text-gray-600 mb-2">Latest responses <span class="text-gray-400 font-normal">· live</span></p>
                <ul class="divide-y divide-gray-100 max-h-80 overflow-y-auto">
                    <template x-for="(r, i) in latest" :key="i">
                        <li class="py-2 flex items-start gap-2 text-sm">
                            <span class="shrink-0" x-text="r.icon"></span>
                            <div class="min-w-0">
                                <span class="font-medium text-gray-800" x-text="r.who"></span><span class="text-gray-600"> — </span><span class="text-gray-600" x-text="r.body"></span>
                                <span class="block text-[11px] text-gray-400" x-text="r.ago"></span>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
        <p x-show="!latest.length" class="text-sm text-gray-500">No responses yet. Replies, poll answers and button clicks land here — and a detailed copy goes to your hook number.</p>
    </x-card>

    @if (!empty($variantStats))
        <x-card title="A/B variant performance" class="mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500 text-left">
                        <tr>
                            <th class="py-2 font-medium">Variant</th>
                            <th class="py-2 font-medium">Copy</th>
                            <th class="py-2 font-medium">Sent</th>
                            <th class="py-2 font-medium">Delivered</th>
                            <th class="py-2 font-medium">Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($variantStats as $v)
                            <tr>
                                <td class="py-2 font-medium text-gray-800 whitespace-nowrap">{{ $v['label'] }}</td>
                                <td class="py-2 text-gray-600 max-w-xs truncate">{{ Str::limit($v['body'], 50) }}</td>
                                <td class="py-2 text-gray-600">{{ $v['sent'] }}</td>
                                <td class="py-2 text-gray-600">{{ $v['delivered'] }}</td>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-green-500" style="width: {{ $v['rate'] }}%"></div></div>
                                        <span class="text-xs text-gray-500">{{ $v['rate'] }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    @if ($trackedLinks->isNotEmpty())
        <x-card title="Link clicks" class="mb-6">
            <ul class="divide-y divide-gray-100">
                @foreach ($trackedLinks as $link)
                    <li class="flex items-center gap-3 py-2">
                        <a href="{{ $link->url }}" target="_blank" rel="noopener" class="text-sm text-gray-600 truncate hover:text-green-600">{{ $link->url }}</a>
                        <span class="ml-auto text-sm font-semibold text-gray-800 whitespace-nowrap">{{ $link->clicks }} click{{ $link->clicks === 1 ? '' : 's' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-card flush>
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Recipients</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Contact</th>
                        <th class="px-5 py-3 font-medium">Phone</th>
                        <th class="px-5 py-3 font-medium">Sent from</th>
                        <th class="px-5 py-3 font-medium">Variant</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Sent at</th>
                        <th class="px-5 py-3 font-medium">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recipients as $r)
                        @php
                            $rc = ['pending' => 'gray', 'sent' => 'blue', 'delivered' => 'blue', 'read' => 'green', 'failed' => 'red'][$r->status] ?? 'gray';
                            $vi = $r->variant_index;
                            $variantLabel = is_null($vi) ? '—' : ($vi === 0 ? 'Main' : 'Variant '.$vi);
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-800 whitespace-nowrap">{{ $r->contact->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">+{{ $r->phone }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $r->instance->name ?? ($campaign->instance->name ?? '—') }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $variantLabel }}</td>
                            <td class="px-5 py-3"><x-badge :color="$rc">{{ ucfirst($r->status) }}</x-badge></td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $r->sent_at?->format('M j, g:i:s A') ?? '—' }}</td>
                            <td class="px-5 py-3 text-red-500 text-xs max-w-xs truncate">{{ $r->error }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">No recipients.</td></tr>
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

        // Live-refresh the Responses card (replies / poll answers / button clicks keep arriving).
        function campaignResponses(id, initial) {
            return {
                engagement: initial.engagement,
                breakdown: initial.poll,
                latest: initial.latest,
                pollPercent(count) {
                    const total = this.breakdown.reduce((s, r) => s + r.count, 0);
                    return total ? Math.round(count / total * 100) : 0;
                },
                startPolling() {
                    setInterval(() => {
                        fetch(`/campaigns/${id}/responses`, { headers: { 'Accept': 'application/json' } })
                            .then(r => r.json())
                            .then(d => { this.engagement = d.engagement; this.breakdown = d.poll; this.latest = d.latest; })
                            .catch(() => {});
                    }, 8000);
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
