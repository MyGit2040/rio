<x-app-layout>
    <x-slot name="header">Contacts</x-slot>

    <x-card flush x-data="bulkSelect({{ \Illuminate\Support\Js::from($contacts->pluck('id')->values()->all()) }})">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 flex-wrap">
            <h2 class="font-semibold text-gray-800">All contacts</h2>
            <div class="ml-auto flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('contacts.verify') }}" onsubmit="return confirm('Check unverified contacts against WhatsApp now?')">
                    @csrf
                    <input type="hidden" name="group" value="{{ request('group') }}">
                    <x-btn type="submit" variant="secondary">Verify WhatsApp</x-btn>
                </form>
                <x-btn :href="route('contacts.export', request()->only('q', 'group', 'status', 'tag'))" variant="secondary" title="Export contacts (CSV)" aria-label="Export" class="!px-2.5">
                    <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </x-btn>
                <x-btn :href="route('contacts.import.create')" variant="secondary" title="Import contacts (CSV)" aria-label="Import CSV" class="!px-2.5">
                    <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </x-btn>
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

        {{-- Bulk action bar --}}
        <x-bulk-bar :action="route('contacts.bulk')">
            <select name="group_id" x-model="groupId" class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                <option value="">Choose group…</option>
                @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
            </select>
            <button type="button" @click="run('add_group', { needGroup: true })" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-sm hover:bg-gray-50">Add to group</button>
            <button type="button" @click="run('remove_group', { needGroup: true })" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-sm hover:bg-gray-50">Remove from group</button>
            <button type="button" @click="run('opt_out')" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-sm hover:bg-gray-50">Opt out</button>
            <button type="button" @click="run('delete', { confirm: 'Delete %d contact(s)? This cannot be undone.' })" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Delete</button>
        </x-bulk-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 w-10"><x-bulk-check /></th>
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
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $contact->id }}) && 'bg-brand/5'">
                            <td class="px-5 py-3"><x-bulk-check :id="$contact->id" /></td>
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
                                <div class="flex justify-end">
                                    <x-contact-actions :contact="$contact" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-5 py-10 text-center text-gray-500">No contacts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($contacts->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $contacts->links() }}</div>
        @endif
    </x-card>

</x-app-layout>
