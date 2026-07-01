<x-app-layout>
    <x-slot name="header">Groups</x-slot>

    <x-card flush x-data="bulkSelect(@js($groups->pluck('id')->values()->all()))">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Contact groups</h2>
            <x-btn :href="route('groups.create')" variant="primary" class="ml-auto">New group</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search groups…" />
        </div>

        <x-bulk-bar :action="route('groups.bulk')">
            <button type="button" @click="run('delete', { confirm: 'Delete %d group(s)? Contacts stay, only the groups are removed.' })" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Delete</button>
        </x-bulk-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 w-10"><x-bulk-check /></th>
                        <th class="px-5 py-3 font-medium">Group</th>
                        <th class="px-5 py-3 font-medium">Contacts</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groups as $group)
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $group->id }}) && 'bg-brand/5'">
                            <td class="px-5 py-3"><x-bulk-check :id="$group->id" /></td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full" style="background: {{ $group->color }}"></span>
                                    <a href="{{ route('groups.show', $group) }}" class="font-medium text-gray-800 hover:text-brand">{{ $group->name }}</a>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-600">{{ $group->contacts_count }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('groups.edit', $group) }}" class="text-green-600 hover:text-green-700">Edit</a>
                                    <form method="POST" action="{{ route('groups.destroy', $group) }}" onsubmit="return confirm('Delete this group? Contacts stay, only the group is removed.')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No groups yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
