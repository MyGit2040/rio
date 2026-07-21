<x-app-layout>
    <x-slot name="header">{{ $campaign->name }}</x-slot>

    <div x-data="{ tab: 'overview' }">

    @if ($campaign->total === 0 && in_array($campaign->status, ['draft', 'scheduled'], true))
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <strong>This campaign has no eligible recipients.</strong> Use the Contacts page to verify the selected numbers on WhatsApp, and make sure they are not opted out. Then create a new campaign for that audience.
        </div>
    @endif

    @if ($campaign->status === 'paused')
        <div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-5 py-4 text-sm flex items-center gap-2 flex-wrap">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><strong>Sending paused</strong> — a WhatsApp number disconnected. Reconnect <strong>any</strong> of this campaign's numbers on the Devices page — or assign different connected numbers in the <strong>Sending numbers</strong> card below — then press <strong>Resume</strong>: it continues from exactly where it stopped and spreads the remaining messages across whatever numbers are online. Nothing is lost. While paused you can also press <strong>Edit</strong> to change the message, speed, per-number limits and more.</span>
        </div>
    @endif

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('campaigns.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; All campaigns</a>
        <x-campaign-status :status="$campaign->status" />
        <div class="ml-auto flex items-center gap-2">
            @if (in_array($campaign->status, ['draft', 'scheduled', 'paused']))
                <x-btn :href="route('campaigns.edit', $campaign)" variant="secondary">Edit</x-btn>
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

    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Campaign sections">
        @foreach (['overview' => 'Overview', 'recipients' => 'Recipients', 'numbers' => 'Sending numbers', 'responses' => 'Responses', 'performance' => 'Performance'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'" class="shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors" :class="tab === '{{ $key }}' ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-gray-800'">{{ $label }}</button>
        @endforeach
    </nav>

    <div x-show="tab === 'overview'" x-cloak class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
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
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Sending numbers</dt><dd class="text-gray-800 truncate">{{ $deviceSummary['assigned'] }} assigned · <span class="text-green-600">{{ $deviceSummary['connected'] }} connected</span>@if ($deviceSummary['disconnected'] > 0)<span class="text-red-500"> · {{ $deviceSummary['disconnected'] }} off</span>@endif</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Type</dt><dd class="text-gray-800">{{ ucfirst($campaign->type) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Delay</dt><dd class="text-gray-800">{{ $campaign->min_delay }}–{{ $campaign->max_delay }}s</dd></div>
                    @if ($campaign->type === 'poll' && ($campaign->body || $campaign->media_url))
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Poll prelude gap</dt><dd class="text-gray-800">Random {{ $campaign->min_delay }}–{{ $campaign->max_delay }}s</dd></div>
                    @endif
                    @if ($campaign->scheduled_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Scheduled</dt><dd class="text-gray-800">@lt($campaign->scheduled_at, 'M j, g:i A')</dd></div>
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

    {{-- Sending numbers: which WhatsApp accounts are assigned + live status --}}
    <x-card x-show="tab === 'numbers'" x-cloak title="Sending numbers" class="mb-6">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs bg-gray-100 text-gray-700">
                <span class="font-semibold">{{ $deviceSummary['assigned'] }}</span> assigned
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs bg-green-50 text-green-700">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                <span class="font-semibold">{{ $deviceSummary['connected'] }}</span> connected
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs {{ $deviceSummary['disconnected'] > 0 ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-500' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $deviceSummary['disconnected'] > 0 ? 'bg-red-500' : 'bg-gray-400' }}"></span>
                <span class="font-semibold">{{ $deviceSummary['disconnected'] }}</span> disconnected
            </span>
        </div>

        @if ($deviceSummary['assigned'] === 0)
            <p class="text-sm text-gray-500">No sending numbers are assigned to this campaign — pick the numbers it should send from below.</p>
        @else
            @if ($deviceSummary['connected'] === 0 && in_array($campaign->status, ['sending', 'scheduled', 'paused', 'draft'], true))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    ⚠️ None of the assigned numbers are connected right now — sending is paused until at least one reconnects.
                    <a href="{{ route('devices.index') }}" class="underline font-medium">Manage numbers →</a>
                </div>
            @elseif ($deviceSummary['disconnected'] > 0)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $deviceSummary['disconnected'] }} assigned number{{ $deviceSummary['disconnected'] === 1 ? ' is' : 's are' }} disconnected — the campaign keeps sending from the connected one{{ $deviceSummary['connected'] === 1 ? '' : 's' }}.
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500 text-left">
                        <tr>
                            <th class="py-2 font-medium">Number</th>
                            <th class="py-2 font-medium">Phone</th>
                            <th class="py-2 font-medium">Status</th>
                            <th class="py-2 font-medium">Assigned</th>
                            <th class="py-2 font-medium">Limit</th>
                            <th class="py-2 font-medium">Connected</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($campaignDevices as $d)
                            @php
                                $connected = $d->status === 'open';
                                $limit = $campaign->deviceLimit($d->id);
                                $assigned = (int) ($deviceAssigned[$d->id] ?? 0);
                            @endphp
                            <tr>
                                <td class="py-2 text-gray-800 whitespace-nowrap">{{ $d->name }}</td>
                                <td class="py-2 text-gray-600 whitespace-nowrap">{{ $d->phone_number ? '+'.$d->phone_number : '—' }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs {{ $connected ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $connected ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                        {{ $connected ? 'Connected' : 'Disconnected' }}
                                    </span>
                                </td>
                                <td class="py-2 text-gray-700 whitespace-nowrap">{{ number_format($assigned) }}</td>
                                <td class="py-2 text-gray-500 whitespace-nowrap">{{ $limit > 0 ? number_format($limit) : 'No limit' }}</td>
                                <td class="py-2 text-gray-500 whitespace-nowrap">{{ $connected && $d->connected_at ? $d->connected_at->diffForHumans() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Assign / change sending numbers: lets a stalled campaign resume on
             freshly-connected devices (added after the campaign was created). --}}
        @if (in_array($campaign->status, ['draft', 'scheduled', 'paused'], true))
            <div x-data="{ open: {{ $deviceSummary['connected'] === 0 ? 'true' : 'false' }} }" class="mt-4 pt-4 border-t border-gray-100">
                <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:underline">
                    <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    Assign / change sending numbers
                </button>

                <div x-show="open" x-cloak class="mt-3">
                    @if ($allDevices->isEmpty())
                        <p class="text-sm text-gray-500">No WhatsApp numbers exist yet — <a href="{{ route('devices.index') }}" class="text-brand underline">connect one on the Devices page</a> first.</p>
                    @else
                        <form method="POST" action="{{ route('campaigns.devices', $campaign) }}" class="space-y-3">
                            @csrf
                            <div class="flex flex-wrap gap-2">
                                @foreach ($allDevices as $d)
                                    <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 bg-white cursor-pointer text-sm hover:bg-gray-50">
                                        <input type="checkbox" name="device_ids[]" value="{{ $d->id }}" @checked(in_array($d->id, $assignedIds, true)) class="rounded border-gray-300 text-brand focus:ring-brand">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $d->status === 'open' ? 'bg-green-500' : 'bg-red-400' }}"></span>
                                        <span class="text-gray-800">{{ $d->name }}</span>
                                        @if ($d->phone_number)<span class="text-gray-400 whitespace-nowrap">+{{ $d->phone_number }}</span>@endif
                                        <span class="text-xs {{ $d->status === 'open' ? 'text-green-600' : 'text-gray-400' }}">{{ $d->status === 'open' ? 'connected' : 'not connected' }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('device_ids')<p class="text-xs text-red-600">Pick at least one number.</p>@enderror
                            <div class="flex items-center gap-3 flex-wrap">
                                <x-btn type="submit" variant="secondary">Save numbers</x-btn>
                                <p class="text-xs text-gray-500">The remaining messages are spread across the <span class="text-green-600 font-medium">connected</span> numbers when you press {{ $campaign->status === 'paused' ? 'Resume' : 'Launch' }}.</p>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </x-card>

    {{-- Responses / engagement (auto-refreshes live) --}}
    @php
        // NOTE: @js(...) is NOT compiled inside a Blade component (<x-card>) attribute —
        // it reaches the browser literally and breaks the Alpine x-data expression, so
        // every x-text binding renders blank. Build the payload here and echo it with
        // {{ Js::from() }} (which IS compiled in component attributes).
        $responsesInitial = [
            'engagement' => $engagement,
            'poll'       => $pollBreakdown->map(fn ($c, $o) => ['option' => $o, 'count' => $c])->values(),
            'latest'     => $responses->map(fn ($r) => [
                'icon' => ['poll_response' => '📊', 'button_response' => '🔘'][$r->type] ?? '📩',
                'who'  => $r->contact->name ?? '+'.$r->phone,
                'body' => Str::limit($r->body, 120),
                'ago'  => $r->created_at?->diffForHumans(),
                'recipient_phone' => '+'.$r->phone,
                'sender_name' => $r->instance?->name ?: 'Unknown device',
                'sender_phone' => $r->instance?->phone_number ? '+'.$r->instance->phone_number : '—',
                'type' => $r->type,
                'received_label' => \App\Support\LocalTime::format($r->created_at, 'd M Y, h:i A'),
            ]),
        ];
    @endphp
    <x-card x-show="tab === 'responses'" x-cloak title="Responses" class="mb-6"
            x-data="campaignResponses({{ $campaign->id }}, {{ \Illuminate\Support\Js::from($responsesInitial) }})"
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
                <div class="flex flex-col gap-2 mb-3 sm:flex-row">
                    <input x-model.debounce.200ms="search" type="search" class="input flex-1" placeholder="Search recipient, phone or answer">
                    <select x-model="type" class="input sm:w-40"><option value="">All responses</option><option value="poll_response">Poll answers</option><option value="button_response">Button clicks</option><option value="text">Replies</option></select>
                    <select x-model="sender" class="input sm:w-48"><option value="">All sending numbers</option><template x-for="option in senderOptions" :key="option"><option :value="option" x-text="option"></option></template></select>
                </div>
                <p class="text-xs font-semibold text-gray-600 mb-2">Responses · live <span class="text-gray-400 font-normal" x-text="`(${filteredRows.length})`"></span></p>
                <div class="overflow-x-auto border border-gray-100 rounded-lg max-h-96">
                    <table class="w-full text-sm"><thead class="sticky top-0 bg-gray-50 text-xs text-gray-500"><tr><th class="px-3 py-2 text-left">Recipient</th><th class="px-3 py-2 text-left">Received by</th><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Answer / message</th><th class="px-3 py-2 text-left">Received at</th></tr></thead><tbody class="divide-y divide-gray-100"><template x-for="r in filteredRows" :key="r.id"><tr class="align-top"><td class="px-3 py-2"><p class="font-medium text-gray-800" x-text="r.who"></p><p class="text-xs text-gray-500" x-text="r.recipient_phone"></p></td><td class="px-3 py-2"><p class="text-gray-800" x-text="r.sender_name"></p><p class="text-xs text-gray-500" x-text="r.sender_phone"></p></td><td class="px-3 py-2"><span class="badge badge-gray" x-text="r.type === 'poll_response' ? 'Poll answer' : (r.type === 'button_response' ? 'Button click' : 'Reply')"></span></td><td class="px-3 py-2 text-gray-700 max-w-sm" x-text="r.body"></td><td class="px-3 py-2 whitespace-nowrap text-gray-600" x-text="r.received_label"></td></tr></template><tr x-show="!filteredRows.length"><td colspan="5" class="px-3 py-6 text-center text-gray-500">No responses match these filters.</td></tr></tbody></table>
                </div>
                <p class="text-xs font-semibold text-gray-600 mb-2">Latest responses <span class="text-gray-400 font-normal">· live</span></p>
                <ul x-show="false" class="divide-y divide-gray-100 max-h-80 overflow-y-auto">
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
        <x-card x-show="tab === 'performance'" x-cloak title="A/B variant performance" class="mb-6">
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
        <x-card x-show="tab === 'performance'" x-cloak title="Link clicks" class="mb-6">
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

    @php
        $statusMeta = [
            'pending'   => ['Pending',   'bg-gray-100 text-gray-700',   'bg-gray-400'],
            'sent'      => ['Sent',      'bg-blue-50 text-blue-700',    'bg-blue-500'],
            'delivered' => ['Delivered', 'bg-indigo-50 text-indigo-700','bg-indigo-500'],
            'read'      => ['Read',      'bg-green-50 text-green-700',   'bg-green-500'],
            'failed'    => ['Failed',    'bg-red-50 text-red-700',       'bg-red-500'],
        ];
        $rate = $dashboard['total'] > 0 ? (int) round($dashboard['delivered'] / $dashboard['total'] * 100) : 0;
        $hasFilters = collect($filters)->only(['status','variant','device','q','from','to'])->filter(fn ($v) => $v !== '' && $v !== null)->isNotEmpty();
    @endphp

    <x-card x-show="tab === 'recipients'" x-cloak flush>
      <div x-data="{ dash: {{ $hasFilters ? 'true' : 'false' }} }">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-3 flex-wrap">
            <h2 class="font-semibold text-gray-800">Recipients</h2>
            <span class="text-xs text-gray-500">{{ number_format($dashboard['total']) }} match{{ $dashboard['total'] === 1 ? '' : 'es' }}</span>
            <button type="button" @click="dash = !dash"
                    class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                <x-nav-icon icon="chart" class="w-4 h-4" />
                <span x-text="dash ? 'Hide dashboard' : 'Dashboard'"></span>
            </button>
        </div>

        {{-- Dashboard --}}
        <div x-show="dash" x-cloak class="px-5 py-4 border-b border-gray-100 bg-gray-50/60 space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                @foreach ([
                    ['Total', $dashboard['total'], 'text-gray-800'],
                    ['Delivered / read', $dashboard['delivered'], 'text-green-600'],
                    ['Sent', $dashboard['sent'], 'text-blue-600'],
                    ['Failed', $dashboard['failed'], 'text-red-600'],
                    ['Pending', $dashboard['pending'], 'text-gray-500'],
                ] as [$lbl, $val, $cls])
                    <div class="bg-white rounded-lg border border-gray-200 px-3 py-2.5">
                        <p class="text-xl font-bold {{ $cls }}">{{ number_format($val) }}</p>
                        <p class="text-[11px] text-gray-500">{{ $lbl }}</p>
                    </div>
                @endforeach
            </div>

            <div>
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                    <span>Delivery rate</span><span class="font-semibold text-gray-700">{{ $rate }}%</span>
                </div>
                <div class="w-full h-2 rounded-full bg-gray-200 overflow-hidden"><div class="h-full bg-green-500" style="width: {{ $rate }}%"></div></div>
            </div>

            {{-- Status chips (click to filter) --}}
            <div class="flex flex-wrap gap-2">
                @foreach ($statusMeta as $key => [$lbl, $chip, $dot])
                    @php $c = (int) ($dashboard['statusCounts'][$key] ?? 0); $active = $filters['status'] === $key; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['status' => $active ? null : $key, 'page' => 1]) }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs {{ $chip }} {{ $active ? 'ring-2 ring-offset-1 ring-gray-400' : '' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>{{ $lbl }} <span class="font-semibold">{{ $c }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Variant + device breakdowns --}}
            <div class="grid sm:grid-cols-2 gap-4">
                @if (count($variantOptions) > 1)
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <p class="text-xs font-semibold text-gray-600 mb-2">By variant</p>
                        <ul class="space-y-1">
                            @foreach ($variantOptions as $opt)
                                <li class="flex justify-between text-xs text-gray-600">
                                    <span>{{ $opt['label'] }}</span>
                                    <span class="font-semibold text-gray-800">{{ (int) ($dashboard['variantCounts'][$opt['value']] ?? 0) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($devices->isNotEmpty())
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <p class="text-xs font-semibold text-gray-600 mb-2">By sending number</p>
                        <ul class="space-y-1">
                            @foreach ($devices as $id => $name)
                                <li class="flex justify-between text-xs text-gray-600">
                                    <span class="truncate">{{ $name }}</span>
                                    <span class="font-semibold text-gray-800">{{ (int) ($dashboard['deviceCounts'][$id] ?? 0) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        {{-- Filter bar --}}
        <form method="GET" action="{{ route('campaigns.show', $campaign) }}" class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-end gap-3">
            <div class="min-w-0">
                <label class="block text-[11px] font-medium text-gray-500 mb-1">Search</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Name or phone"
                       class="rounded-lg border-gray-300 text-sm w-44 focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-500 mb-1">Status</label>
                <select name="status" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    <option value="">All</option>
                    @foreach ($statusMeta as $key => [$lbl])
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            @if (count($variantOptions) > 1)
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 mb-1">Variant</label>
                    <select name="variant" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        <option value="">All</option>
                        @foreach ($variantOptions as $opt)
                            <option value="{{ $opt['value'] }}" @selected((string) $filters['variant'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($devices->isNotEmpty())
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 mb-1">Sending number</label>
                    <select name="device" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        <option value="">All</option>
                        @foreach ($devices as $id => $name)
                            <option value="{{ $id }}" @selected((string) $filters['device'] === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-[11px] font-medium text-gray-500 mb-1">Sent from</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-500 mb-1">Sent to</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label class="block text-[11px] font-medium text-gray-500 mb-1">Per page</label>
                <select name="per_page" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    @foreach (['10','25','50','100','all'] as $pp)
                        <option value="{{ $pp }}" @selected($filters['per_page'] === $pp)>{{ $pp === 'all' ? 'All' : $pp }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="px-3 py-2 rounded-lg bg-brand text-white text-sm hover:opacity-90">Apply</button>
                @if ($hasFilters || $filters['per_page'] !== '25')
                    <a href="{{ route('campaigns.show', $campaign) }}" class="px-3 py-2 rounded-lg border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">Reset</a>
                @endif
            </div>
        </form>

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
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">@lt($r->sent_at, 'M j, g:i:s A')</td>
                            <td class="px-5 py-3 text-red-500 text-xs max-w-xs truncate">{{ $r->error }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">{{ $hasFilters ? 'No recipients match these filters.' : 'No recipients.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-3 flex-wrap">
            <p class="text-xs text-gray-500">
                @if ($recipients->total() > 0)
                    Showing {{ number_format($recipients->firstItem()) }}–{{ number_format($recipients->lastItem()) }} of {{ number_format($recipients->total()) }}
                @else
                    No results
                @endif
            </p>
            @if ($recipients->hasPages())
                <div>{{ $recipients->onEachSide(1)->links() }}</div>
            @endif
        </div>
      </div>
    </x-card>

    </div>

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
                search: '',
                type: '',
                sender: '',
                get senderOptions() {
                    return [...new Set(this.latest.map(r => r.sender_name).filter(Boolean))].sort();
                },
                get filteredRows() {
                    const needle = this.search.trim().toLowerCase();
                    return this.latest.filter(r => (!this.type || r.type === this.type)
                        && (!this.sender || r.sender_name === this.sender)
                        && (!needle || [r.who, r.recipient_phone, r.sender_name, r.sender_phone, r.body].join(' ').toLowerCase().includes(needle)));
                },
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
