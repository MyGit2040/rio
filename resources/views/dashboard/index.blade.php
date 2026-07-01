<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    @php
        $optInPct = $stats['contacts'] > 0 ? (int) round($stats['opted_in'] / $stats['contacts'] * 100) : 0;
        $plan = ucfirst(auth()->user()->tenant->plan ?? 'free');
    @endphp

    {{-- Status bar --}}
    <div class="flex items-center gap-3 flex-wrap text-sm mb-5">
        <span class="inline-flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            <span class="text-gray-600">System Status</span>
            <x-badge color="green">Online</x-badge>
        </span>
        <div class="ml-auto flex items-center gap-4 text-gray-500">
            <span>Last updated: {{ now()->format('g:i A') }}</span>
            <a href="{{ route('dashboard') }}" class="text-brand font-medium">Refresh data</a>
        </div>
    </div>

    {{-- Welcome banner --}}
    <div class="banner-brand rounded-2xl text-white p-6 sm:p-8 mb-6 flex items-center gap-6 relative overflow-hidden">
        <div class="min-w-0 relative z-10">
            <h2 class="text-2xl font-bold">Welcome back!</h2>
            <p class="text-white/80 mt-1">Here's what's happening with your WhatsApp automation today.</p>
            <div class="flex items-center gap-4 flex-wrap mt-4 text-sm">
                <span class="inline-flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-green-300"></span>{{ $stats['connected'] }} devices online</span>
                <span class="inline-flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-sky-300"></span>{{ $stats['messages_today'] }} messages today</span>
                <span class="inline-flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-300"></span>{{ $plan }} plan</span>
            </div>
        </div>
        <div class="ml-auto hidden sm:grid place-items-center w-24 h-24 rounded-2xl bg-white/15 backdrop-blur relative z-10">
            <svg class="w-12 h-12 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M21 12a8 8 0 01-11.6 7.1L3 21l1.9-6.4A8 8 0 1121 12z"/></svg>
        </div>
        <div class="absolute -right-10 -top-10 w-48 h-48 rounded-full bg-white/5"></div>
    </div>

    {{-- Alerts --}}
    @if ($alerts->isNotEmpty())
        @php $alertClasses = ['error'=>'bg-red-50 border-red-200 text-red-800','warning'=>'bg-yellow-50 border-yellow-200 text-yellow-800','info'=>'bg-blue-50 border-blue-200 text-blue-800']; @endphp
        <div class="mb-6 space-y-2">
            @foreach ($alerts as $alert)
                <div class="rounded-lg border px-4 py-3 text-sm flex items-start gap-2 {{ $alertClasses[$alert->level] ?? $alertClasses['info'] }}">
                    <span class="font-medium">{{ $alert->title }}</span>
                    @if ($alert->body)<span class="opacity-80">— {{ $alert->body }}</span>@endif
                    <span class="ml-auto text-xs opacity-70 whitespace-nowrap">{{ $alert->created_at->diffForHumans() }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Primary stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
        @php
            $sparkline = '<svg viewBox="0 0 60 20" class="w-14 h-5"><polyline fill="none" stroke="currentColor" stroke-width="2" points="0,16 12,12 24,14 36,6 48,9 60,3"/></svg>';
            $cards = [
                ['Messages sent', number_format($stats['sent']), $stats['messages_today'].' today', 'blue', 'chat', route('campaigns.index')],
                ['Active devices', $stats['connected'], $stats['devices'].' total sessions', 'green', 'device', route('devices.index')],
                ['Total contacts', number_format($stats['contacts']), $stats['opted_in'].' opted-in', 'purple', 'users', route('contacts.index')],
                ['Templates', number_format($stats['templates']), 'reusable layouts', 'orange', 'doc', route('templates.index')],
            ];
            $tile = ['blue'=>'bg-blue-500','green'=>'bg-green-500','purple'=>'bg-purple-500','orange'=>'bg-orange-500'];
            $svg = [
                'chat'=>'M8 10h.01M12 10h.01M16 10h.01M21 12a8 8 0 01-11.6 7.1L3 21l1.9-6.4A8 8 0 1121 12z',
                'device'=>'M7 4a1 1 0 011-1h8a1 1 0 011 1v16a1 1 0 01-1 1H8a1 1 0 01-1-1V4zm4 14h2',
                'users'=>'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z',
                'doc'=>'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            ];
        @endphp
        @foreach ($cards as [$label, $value, $sub, $color, $icon, $href])
            <a href="{{ $href }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md transition">
                <div class="flex items-start justify-between">
                    <p class="text-sm text-gray-500">{{ $label }}</p>
                    <span class="grid place-items-center w-10 h-10 rounded-lg text-white {{ $tile[$color] }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $svg[$icon] }}"/></svg>
                    </span>
                </div>
                <p class="text-3xl font-bold text-gray-800 mt-1">{{ $value }}</p>
                <p class="text-xs text-gray-400 mt-1 flex items-center gap-1">
                    <span class="text-green-500">{!! $sparkline !!}</span> {{ $sub }}
                </p>
            </a>
        @endforeach
    </div>

    {{-- Secondary feature cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        @php
            $secondary = [
                ['Auto reply', 'Active rules', $stats['rules_active'], 'Total responses', $stats['auto_replies'], $rings['autoreply'], 'bg-yellow-400'],
                ['Chatbot', 'Active flows', $stats['rules_total'], 'Conversations', $stats['conversations'], $rings['delivery'], 'bg-blue-500'],
                ['Bulk campaigns', 'Completed', $stats['campaigns_done'], 'Success rate', $rings['success'].'%', $rings['success'], 'bg-purple-500'],
                ['Opt-in health', 'Opted-in', $stats['opted_in'], 'Of total', $stats['contacts'], $optInPct, 'bg-green-500'],
            ];
        @endphp
        @foreach ($secondary as [$title, $l1, $v1, $l2, $v2, $pct, $bar])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <p class="font-semibold text-gray-800 mb-3">{{ $title }}</p>
                <div class="flex justify-between text-sm mb-1"><span class="text-gray-500">{{ $l1 }}</span><span class="font-semibold text-gray-800">{{ $v1 }}</span></div>
                <div class="flex justify-between text-sm mb-3"><span class="text-gray-500">{{ $l2 }}</span><span class="font-semibold text-gray-800">{{ $v2 }}</span></div>
                <div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full {{ $bar }}" style="width: {{ $pct }}%"></div></div>
            </div>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2">
            <x-card title="Messages sent" subtitle="Last 14 days">
                <div class="h-64"><canvas id="messagesChart"></canvas></div>
            </x-card>
        </div>
        <div>
            <x-card title="Delivery breakdown">
                <div class="h-64"><canvas id="breakdownChart"></canvas></div>
            </x-card>
        </div>
    </div>

    {{-- Performance rings --}}
    <x-card title="Performance overview" subtitle="Calculated from your real data — fills in as you send." class="mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 py-2">
            <x-stat-ring :percent="$rings['success']" label="Message success rate" :sublabel="$stats['sent'].' sent, '.$stats['failed'].' failed'" color="#16a34a" />
            <x-stat-ring :percent="$rings['autoreply']" label="Auto reply usage" :sublabel="$stats['rules_active'].' of '.$stats['rules_total'].' rules active'" color="#f59e0b" />
            <x-stat-ring :percent="$rings['delivery']" label="Delivery rate" sublabel="delivered / sent" color="#2563eb" />
        </div>
    </x-card>

    {{-- Recent activity + quick actions --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card title="Recent activity">
                @forelse ($recentMessages as $m)
                    <div class="flex items-center gap-3 py-2.5 border-b border-gray-100 last:border-0">
                        <span class="grid place-items-center w-8 h-8 rounded-full {{ $m->direction === 'in' ? 'bg-blue-50 text-blue-600' : 'bg-green-50 text-green-600' }} text-xs font-semibold">
                            {{ $m->direction === 'in' ? '↓' : '↑' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-800 truncate">{{ $m->contact->name ?? ('+'.$m->phone) }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ Str::limit($m->body, 60) ?: ucfirst($m->type) }}</p>
                        </div>
                        <span class="text-xs text-gray-400 whitespace-nowrap">{{ $m->created_at->diffForHumans(short: true) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 py-6 text-center">No activity yet — send your first campaign to see it here.</p>
                @endforelse
            </x-card>
        </div>
        <div>
            <x-card title="Quick actions">
                <div class="grid grid-cols-2 gap-3">
                    <x-btn :href="route('campaigns.create')" variant="primary" class="w-full">New campaign</x-btn>
                    <x-btn :href="route('devices.index')" variant="secondary" class="w-full">Add device</x-btn>
                    <x-btn :href="route('contacts.import.create')" variant="secondary" class="w-full">Import contacts</x-btn>
                    <x-btn :href="route('templates.create')" variant="secondary" class="w-full">New template</x-btn>
                </div>
            </x-card>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            if (typeof Chart === 'undefined') return;
            const series = @json($series);
            const breakdown = @json($breakdown);
            const accent = (getComputedStyle(document.documentElement).getPropertyValue('--brand') || '#8b5cf6').trim();

            new Chart(document.getElementById('messagesChart'), {
                type: 'line',
                data: { labels: series.map(p => p.label), datasets: [{
                    data: series.map(p => p.value), borderColor: accent, backgroundColor: accent + '22',
                    fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                }]},
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });

            new Chart(document.getElementById('breakdownChart'), {
                type: 'doughnut',
                data: { labels: Object.keys(breakdown), datasets: [{
                    data: Object.values(breakdown),
                    backgroundColor: ['#3b82f6', '#6366f1', '#16a34a', '#ef4444', '#9ca3af'], borderWidth: 0,
                }]},
                options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom' } } }
            });
        })();
    </script>
    @endpush
</x-app-layout>
