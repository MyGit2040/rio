<x-app-layout>
    <x-slot name="header">Drip sequences</x-slot>

    <x-card flush x-data="bulkSelect(@js($sequences->pluck('id')->values()->all()))">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Follow-up sequences</h2>
            <x-btn :href="route('sequences.create')" variant="primary" class="ml-auto">New sequence</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search sequences…" :filters="[
                'status' => ['all' => 'All Statuses', 'options' => ['active' => 'Active', 'paused' => 'Paused']],
            ]" />
        </div>

        <x-bulk-bar :action="route('sequences.bulk')">
            <button type="button" @click="run('delete', { confirm: 'Delete %d sequence(s)? This cannot be undone.' })" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Delete</button>
        </x-bulk-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 w-10"><x-bulk-check /></th>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Steps</th>
                        <th class="px-5 py-3 font-medium">Enrolled</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sequences as $sequence)
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $sequence->id }}) && 'bg-brand/5'">
                            <td class="px-5 py-3"><x-bulk-check :id="$sequence->id" /></td>
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">
                                <a href="{{ route('sequences.show', $sequence) }}" class="hover:text-green-600">{{ $sequence->name }}</a>
                            </td>
                            <td class="px-5 py-3 text-gray-600">{{ $sequence->steps_count }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $sequence->enrollments_count }}</td>
                            <td class="px-5 py-3"><x-badge :color="$sequence->is_active ? 'green' : 'gray'">{{ $sequence->is_active ? 'Active' : 'Paused' }}</x-badge></td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('sequences.edit', $sequence) }}" class="text-green-600 hover:text-green-700">Edit</a>
                                    <form method="POST" action="{{ route('sequences.destroy', $sequence) }}" onsubmit="return confirm('Delete this sequence?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No sequences yet. Create one to automatically follow up with contacts over time.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sequences->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $sequences->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
