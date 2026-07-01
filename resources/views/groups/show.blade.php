<x-app-layout>
    <x-slot name="header">{{ $group->name }}</x-slot>

    <a href="{{ route('groups.index') }}" class="text-sm text-gray-500 hover:text-gray-700 inline-block mb-4">&larr; All groups</a>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 grid grid-cols-3 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">Total</p><p class="text-2xl font-bold text-gray-800">{{ $counts['total'] }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">On WhatsApp</p><p class="text-2xl font-bold text-green-600">{{ $counts['valid'] }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">Unverified</p><p class="text-2xl font-bold text-gray-500">{{ $counts['unverified'] }}</p></div>
        </div>
        <div class="space-y-3">
            <x-card>
                <div class="flex items-center gap-2 mb-2">
                    <p class="text-sm font-medium text-gray-800">Import contacts</p>
                    <a href="{{ route('contacts.import.sample') }}" class="ml-auto text-xs text-brand font-medium hover:underline">Download sample</a>
                </div>
                <form method="POST" action="{{ route('groups.import', $group) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <input name="file" type="file" accept=".csv,.txt,.xls,.xlsx" required class="block w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand file:text-white">
                    <x-btn type="submit" variant="secondary" class="w-full">Import into group</x-btn>
                </form>
                <p class="text-xs text-gray-500 mt-1">Needs a <strong>name</strong> and <strong>number</strong> column (with country code, e.g. 971501234567). Save Excel as <strong>CSV</strong> first. Duplicates are skipped.</p>
            </x-card>
            <form method="POST" action="{{ route('groups.verify', $group) }}" onsubmit="return confirm('Verify this group\'s WhatsApp numbers now? It runs gently in the background.')">
                @csrf
                <x-btn type="submit" variant="primary" class="w-full">Verify WhatsApp numbers</x-btn>
            </form>
            @if ($counts['unverified'] > 0)
                <p class="text-xs text-gray-500 text-center">Verification runs ~20 numbers at a time with pauses, so the account stays safe. Refresh to see progress.</p>
            @endif
        </div>
    </div>

    <x-card flush>
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Contacts</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Phone</th>
                        <th class="px-5 py-3 font-medium">WhatsApp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($contacts as $contact)
                        @php $wa = ['valid' => ['green', 'On WhatsApp'], 'invalid' => ['red', 'Not found'], 'unverified' => ['gray', 'Unverified']][$contact->wa_status] ?? ['gray', 'Unverified']; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-800 whitespace-nowrap">{{ $contact->name ?: '—' }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">+{{ $contact->phone }}</td>
                            <td class="px-5 py-3"><x-badge :color="$wa[0]">{{ $wa[1] }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-5 py-10 text-center text-gray-500">No contacts in this group yet — import some above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($contacts->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $contacts->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
