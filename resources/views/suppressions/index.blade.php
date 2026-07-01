<x-app-layout>
    <x-slot name="header">Do-not-contact list</x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card flush>
                <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
                    <h2 class="font-semibold text-gray-800">Blocked numbers</h2>
                    <span class="text-sm text-gray-500">{{ $suppressions->total() }} total</span>
                </div>
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
                    <x-filter-bar search="Search number…" :filters="[
                        'source' => ['all' => 'All Sources', 'options' => ['manual' => 'Manual', 'opt_out' => 'Opt-out', 'import' => 'Import', 'bounce' => 'Bounce']],
                    ]" :dates="['created_from' => 'Added from', 'created_to' => 'Added to']" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-left">
                            <tr>
                                <th class="px-5 py-3 font-medium">Number</th>
                                <th class="px-5 py-3 font-medium">Reason</th>
                                <th class="px-5 py-3 font-medium">Source</th>
                                <th class="px-5 py-3 font-medium">Added</th>
                                <th class="px-5 py-3 font-medium text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($suppressions as $s)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">+{{ $s->phone }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $s->reason ?? '—' }}</td>
                                    <td class="px-5 py-3"><x-badge :color="$s->source === 'opt_out' ? 'yellow' : 'gray'">{{ str_replace('_', ' ', $s->source) }}</x-badge></td>
                                    <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $s->created_at->format('M j, Y') }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <form method="POST" action="{{ route('suppressions.destroy', $s) }}" onsubmit="return confirm('Remove +{{ $s->phone }} from the block list?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:text-red-700">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No blocked numbers. Contacts who reply an opt-out keyword land here automatically.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($suppressions->hasPages())
                    <div class="px-5 py-3 border-t border-gray-100">{{ $suppressions->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Block a number">
                <form method="POST" action="{{ route('suppressions.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="phone" value="Phone" />
                        <x-text-input id="phone" name="phone" class="block mt-1 w-full" placeholder="971501234567" required />
                    </div>
                    <div>
                        <x-input-label for="reason" value="Reason (optional)" />
                        <x-text-input id="reason" name="reason" class="block mt-1 w-full" placeholder="Requested removal" />
                    </div>
                    <x-btn type="submit" variant="primary">Add to list</x-btn>
                </form>
            </x-card>

            <x-card title="Bulk import">
                <form method="POST" action="{{ route('suppressions.import') }}" class="space-y-3">
                    @csrf
                    <textarea name="numbers" rows="5" placeholder="One number per line" required
                              class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                    <x-btn type="submit" variant="secondary">Import numbers</x-btn>
                </form>
            </x-card>
        </div>
    </div>
</x-app-layout>
