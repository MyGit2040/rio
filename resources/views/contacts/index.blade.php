<x-app-layout>
    <x-slot name="header">Contacts</x-slot>

    <x-card flush>
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 flex-wrap">
            <h2 class="font-semibold text-gray-800">All contacts</h2>
            <div class="ml-auto flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('contacts.verify') }}" onsubmit="return confirm('Check unverified contacts against WhatsApp now?')">
                    @csrf
                    <input type="hidden" name="group" value="{{ request('group') }}">
                    <x-btn type="submit" variant="secondary">Verify WhatsApp</x-btn>
                </form>
                <x-btn :href="route('contacts.import.create')" variant="secondary">Import CSV</x-btn>
                <x-btn :href="route('contacts.create')" variant="primary">Add contact</x-btn>
            </div>
        </div>

        {{-- Filter bar --}}
        <form method="GET" class="flex items-center gap-2 px-5 py-3 border-b border-gray-100 flex-wrap bg-gray-50/50">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search name, phone or email…"
                   class="rounded-lg border-gray-300 text-sm min-w-0 flex-1 focus:ring-green-500 focus:border-green-500">
            <select name="group" class="rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <option value="">All groups</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected(request('group') == $g->id)>{{ $g->name }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="opted_out" @selected(request('status') === 'opted_out')>Opted out</option>
            </select>
            <x-btn type="submit" variant="secondary">Filter</x-btn>
            @if (request()->hasAny(['q', 'group', 'status']))
                <a href="{{ route('contacts.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Phone</th>
                        <th class="px-5 py-3 font-medium">Email</th>
                        <th class="px-5 py-3 font-medium">Groups</th>
                        <th class="px-5 py-3 font-medium">WhatsApp</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($contacts as $contact)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap"><a href="{{ route('contacts.show', $contact) }}" class="hover:text-green-600">{{ $contact->name ?: '—' }}</a></td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">+{{ $contact->phone }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $contact->email ?: '—' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex gap-1 flex-wrap">
                                    @foreach ($contact->groups as $group)
                                        <x-badge color="gray">{{ $group->name }}</x-badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                @php $wa = ['valid' => ['green', 'On WhatsApp'], 'invalid' => ['red', 'Not found'], 'unverified' => ['gray', 'Unverified']][$contact->wa_status] ?? ['gray', 'Unverified']; @endphp
                                <x-badge :color="$wa[0]">{{ $wa[1] }}</x-badge>
                            </td>
                            <td class="px-5 py-3">
                                @if ($contact->opted_out)
                                    <x-badge color="red">Opted out</x-badge>
                                @else
                                    <x-badge color="green">Active</x-badge>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('contacts.show', $contact) }}" class="text-gray-500 hover:text-gray-700">View</a>
                                    <a href="{{ route('contacts.edit', $contact) }}" class="text-green-600 hover:text-green-700">Edit</a>
                                    <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('Delete this contact?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">No contacts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($contacts->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $contacts->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
