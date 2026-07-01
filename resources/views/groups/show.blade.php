<x-app-layout>
    <x-slot name="header">{{ $group->name }}</x-slot>

    <a href="{{ route('groups.index') }}" class="text-sm text-gray-500 hover:text-gray-700 inline-block mb-4">&larr; All groups</a>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6"
         x-data="verifier({{ $group->id }}, @js($counts))" x-init="init()">

        {{-- Verification progress --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="grid grid-cols-4 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">Total</p><p class="text-2xl font-bold text-gray-800" x-text="total"></p></div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">On WhatsApp</p><p class="text-2xl font-bold text-green-600" x-text="valid"></p></div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">Not found</p><p class="text-2xl font-bold text-red-500" x-text="invalid"></p></div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><p class="text-xs text-gray-500">Left</p><p class="text-2xl font-bold text-gray-500" x-text="unverified"></p></div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <div class="flex items-center justify-between text-sm mb-2">
                    <span class="text-gray-600">Verified <span class="font-semibold text-gray-800" x-text="verified"></span> of <span x-text="total"></span></span>
                    <span class="font-medium" x-text="percent + '%'"></span>
                </div>
                <div class="h-3 rounded-full bg-gray-100 overflow-hidden">
                    <div class="h-full bg-green-500 transition-all" :style="`width:${percent}%`"></div>
                </div>
                <p x-show="running" x-cloak class="text-xs text-gray-500 mt-2">Checking ~20 numbers at a time with pauses so your number stays safe…</p>
                <p x-show="error" x-cloak class="text-xs text-red-600 mt-2" x-text="error"></p>
            </div>
        </div>

        {{-- Actions --}}
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

            <button type="button" @click="verify()" :disabled="running || unverified === 0"
                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium disabled:opacity-50">
                <span x-show="!running" x-text="unverified > 0 ? 'Verify WhatsApp numbers' : 'All verified ✓'"></span>
                <span x-show="running" x-cloak>Verifying… <span x-text="verified"></span>/<span x-text="total"></span></span>
            </button>

            <template x-if="invalid > 0">
                <div class="flex gap-2">
                    <button type="button" @click="reverify()" :disabled="running"
                            class="flex-1 px-3 py-2 rounded-lg bg-white border border-gray-300 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                        Re-check not-found (<span x-text="invalid"></span>)
                    </button>
                    <button type="button" @click="deleteInvalid()"
                            class="flex-1 px-3 py-2 rounded-lg bg-white border border-red-200 text-xs font-medium text-red-600 hover:bg-red-50">
                        Delete not-found
                    </button>
                </div>
            </template>
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

    @push('scripts')
    <script>
        function verifier(groupId, counts) {
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            return {
                groupId,
                total: counts.total, valid: counts.valid, invalid: counts.invalid,
                unverified: counts.unverified, verified: counts.verified, percent: counts.percent,
                running: false, error: '', timer: null,

                init() {
                    // If a background verification is already mid-way, start tracking it.
                    if (this.unverified > 0 && this.verified > 0) this.startPolling();
                },
                apply(d) {
                    this.total = d.total; this.valid = d.valid; this.invalid = d.invalid;
                    this.unverified = d.unverified; this.verified = d.verified; this.percent = d.percent;
                    if (d.done) { this.stopPolling(); this.running = false; }
                },
                refresh() {
                    fetch(`/groups/${this.groupId}/progress`, { headers: { 'Accept': 'application/json' } })
                        .then(r => r.json()).then(d => this.apply(d)).catch(() => {});
                },
                startPolling() {
                    this.running = true;
                    this.refresh();
                    if (! this.timer) this.timer = setInterval(() => this.refresh(), 4000);
                },
                stopPolling() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },

                verify() {
                    this.error = '';
                    fetch(`/groups/${this.groupId}/verify`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                        .then(r => r.json())
                        .then(d => { if (d.ok) this.startPolling(); else { this.error = d.message || 'Could not start.'; } })
                        .catch(() => { this.error = 'Could not reach the server.'; });
                },
                reverify() {
                    this.error = '';
                    fetch(`/groups/${this.groupId}/reverify`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                        .then(r => r.json())
                        .then(d => { if (d.ok) this.startPolling(); else { this.error = d.message || 'Could not start.'; } })
                        .catch(() => { this.error = 'Could not reach the server.'; });
                },
                deleteInvalid() {
                    if (! confirm(`Delete ${this.invalid} number(s) that are NOT on WhatsApp? This removes the contacts.`)) return;
                    fetch(`/groups/${this.groupId}/invalid`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                        .then(r => r.json())
                        .then(() => location.reload())
                        .catch(() => { this.error = 'Delete failed.'; });
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
