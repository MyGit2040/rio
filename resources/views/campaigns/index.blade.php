<x-app-layout>
    <x-slot name="header">Bulk messages</x-slot>

    {{-- Campaign dashboard --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-3">
        @php
            $cards = [
                ['Total', $stats['total'], 'text-blue-600'],
                ['Running', $stats['running'], 'text-emerald-600'],
                ['Scheduled', $stats['scheduled'], 'text-indigo-600'],
                ['Completed', $stats['completed'], 'text-violet-600'],
                ['Messages sent', number_format($stats['sent']), 'text-sky-600'],
            ];
        @endphp
        @foreach ($cards as [$label, $value, $tone])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <p class="text-xs text-gray-500 truncate">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $tone }} mt-1">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-6">
        @php
            $engagementCards = [
                ['Success rate', $stats['success_rate'].'%', 'text-green-600'],
                ['Failed', number_format($stats['failed']), 'text-red-600'],
                ['Replies received', number_format($stats['replies']), 'text-brand'],
                ['Poll answers', number_format($stats['poll_answers']), 'text-green-600'],
                ['Button clicks', number_format($stats['button_clicks']), 'text-blue-600'],
            ];
        @endphp
        @foreach ($engagementCards as [$label, $value, $tone])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <p class="text-xs text-gray-500 truncate">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $tone }} mt-1">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <x-card flush x-data="bulkSelect({{ \Illuminate\Support\Js::from($campaigns->pluck('id')->values()->all()) }})">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Campaigns</h2>
            <x-btn :href="route('campaigns.create')" variant="primary" class="ml-auto">New campaign</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search campaigns…" :filters="[
                'status' => ['all' => 'All Statuses', 'options' => ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'paused' => 'Paused', 'completed' => 'Completed']],
                'type'   => ['all' => 'All Types', 'options' => ['text' => 'Text', 'media' => 'Media', 'poll' => 'Poll', 'buttons' => 'Buttons', 'carousel' => 'Carousel']],
            ]" :dates="['created_from' => 'Created from', 'created_to' => 'Created to']" />
        </div>

        <x-bulk-bar :action="route('campaigns.bulk')">
            <button type="button" @click="run('delete', { confirm: 'Delete %d campaign(s)? This cannot be undone.' })" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Delete</button>
        </x-bulk-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 w-10"><x-bulk-check /></th>
                        <th class="px-5 py-3 font-medium">Campaign</th>
                        <th class="px-5 py-3 font-medium">Sending numbers</th>
                        <th class="px-5 py-3 font-medium">Progress</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Created</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($campaigns as $campaign)
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $campaign->id }}) && 'bg-brand/5'">
                            <td class="px-5 py-3"><x-bulk-check :id="$campaign->id" /></td>
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="hover:text-green-600">{{ $campaign->name }}</a>
                            </td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                @php
                                    $ids = $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]);
                                    $assigned = count($ids);
                                    $connected = collect($ids)->filter(fn ($id) => $deviceStatus[$id] ?? false)->count();
                                @endphp
                                @if ($assigned === 0)
                                    <span class="text-gray-400">—</span>
                                @elseif ($assigned === 1)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $connected ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                        {{ $campaign->instance->name ?? '1 number' }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5" title="{{ $connected }} of {{ $assigned }} sending numbers connected">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $connected > 0 ? ($connected === $assigned ? 'bg-green-500' : 'bg-amber-500') : 'bg-red-500' }}"></span>
                                        {{ $assigned }} numbers · {{ $connected }} on
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 min-w-[170px]">
                                <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                                    <span>{{ $campaign->sent }}/{{ $campaign->total }}</span>
                                    <span>{{ $campaign->progressPercent() }}%</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full bg-brand" style="width: {{ $campaign->progressPercent() }}%"></div>
                                </div>
                                @if($campaign->failed)<span class="text-xs text-red-500">{{ $campaign->failed }} failed</span>@endif
                            </td>
                            <td class="px-5 py-3"><x-campaign-status :status="$campaign->status" /></td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $campaign->created_at->format('M j, Y') }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('campaigns.show', $campaign) }}" class="text-green-600 hover:text-green-700">View</a>
                                    <form method="POST" action="{{ route('campaigns.destroy', $campaign) }}" onsubmit="return confirm('Delete this campaign?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">No campaigns yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($campaigns->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $campaigns->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
