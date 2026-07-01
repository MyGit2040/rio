<x-app-layout>
    <x-slot name="header">Reports</x-slot>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach ([
            ['Campaigns', $totals['campaigns'], 'text-gray-800'],
            ['Messages sent', $totals['sent'], 'text-green-600'],
            ['Failed', $totals['failed'], 'text-red-500'],
            ['Link clicks', $totals['clicks'], 'text-brand'],
        ] as [$label, $value, $color])
            <x-card><p class="text-2xl font-bold {{ $color }}">{{ number_format($value) }}</p><p class="text-xs text-gray-500 mt-1">{{ $label }}</p></x-card>
        @endforeach
    </div>

    <x-card flush class="mb-6">
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Campaign performance</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Campaign</th>
                        <th class="px-5 py-3 font-medium">Sent</th>
                        <th class="px-5 py-3 font-medium">Delivered</th>
                        <th class="px-5 py-3 font-medium">Read</th>
                        <th class="px-5 py-3 font-medium">Failed</th>
                        <th class="px-5 py-3 font-medium">Delivery rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($campaigns as $c)
                        @php($rate = $c->sent > 0 ? (int) round($c->delivered_count / max(1, $c->sent) * 100) : 0)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap"><a href="{{ route('campaigns.show', $c) }}" class="hover:text-green-600">{{ $c->name }}</a></td>
                            <td class="px-5 py-3 text-gray-600">{{ $c->sent }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $c->delivered_count }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $c->read_count }}</td>
                            <td class="px-5 py-3 text-red-500">{{ $c->failed }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-20 h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-green-500" style="width: {{ $rate }}%"></div></div>
                                    <span class="text-xs text-gray-500">{{ $rate }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No campaigns yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card flush>
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Top tracked links</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Link</th>
                        <th class="px-5 py-3 font-medium">Campaign</th>
                        <th class="px-5 py-3 font-medium text-right">Clicks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($topLinks as $link)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-600 max-w-md truncate"><a href="{{ $link->url }}" target="_blank" rel="noopener" class="hover:text-green-600">{{ $link->url }}</a></td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $link->campaign->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-gray-800">{{ $link->clicks }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-5 py-10 text-center text-gray-500">No link clicks recorded yet. Turn on link tracking when creating a campaign.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
