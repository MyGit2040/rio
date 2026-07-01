<x-app-layout>
    <x-slot name="header">Devices</x-slot>

    @unless ($engineReady)
        <div class="mb-6 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 px-5 py-4 text-sm flex items-center gap-3 flex-wrap">
            <span>The Evolution engine isn't connected yet — you need it before linking a WhatsApp number.</span>
            <x-btn :href="route('settings.edit')" variant="secondary" class="ml-auto">Open Settings</x-btn>
        </div>
    @endunless

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-card title="Add a device">
                <form method="POST" action="{{ route('devices.store') }}" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="name" value="Device name" />
                        <x-text-input id="name" name="name" class="block mt-1 w-full" placeholder="e.g. Sales line" :value="old('name')" required :disabled="! $engineReady" />
                    </div>
                    <div>
                        <x-input-label for="daily_limit" value="Daily send cap (0 = unlimited)" />
                        <x-text-input id="daily_limit" name="daily_limit" type="number" min="0" class="block mt-1 w-full" :value="old('daily_limit', 0)" :disabled="! $engineReady" />
                    </div>
                    <div>
                        <x-input-label for="phone_for_pairing" value="Phone number (optional — link by code)" />
                        <x-text-input id="phone_for_pairing" name="phone_for_pairing" class="block mt-1 w-full" placeholder="971501234567" :value="old('phone_for_pairing')" :disabled="! $engineReady" />
                        <p class="text-xs text-gray-500 mt-1">Fill this to get an 8-digit code instead of scanning a QR.</p>
                    </div>
                    <x-btn type="submit" variant="primary" class="w-full" :disabled="! $engineReady">Create &amp; link</x-btn>
                    <p class="text-xs text-gray-500">After creating, scan the QR code with WhatsApp → Linked devices.</p>
                </form>
            </x-card>
        </div>

        <div class="lg:col-span-2">
            @if ($devices->isEmpty())
                <x-card>
                    <p class="text-sm text-gray-500 py-8 text-center">No devices yet. Add one to connect a WhatsApp number.</p>
                </x-card>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ($devices as $device)
                        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5" data-device-id="{{ $device->id }}">
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('devices.show', $device) }}" class="font-semibold text-gray-800 truncate hover:text-brand block">{{ $device->name }}</a>
                                    <p class="text-xs text-gray-400 truncate">{{ $device->instance_name }}</p>
                                </div>
                                @php
                                    $statusColor = ['open' => 'green', 'connecting' => 'yellow', 'close' => 'red'][$device->status] ?? 'gray';
                                    $statusLabel = ['open' => 'Connected', 'connecting' => 'Waiting for scan', 'close' => 'Disconnected'][$device->status] ?? ucfirst($device->status);
                                @endphp
                                <x-badge :color="$statusColor" data-device-status>{{ $statusLabel }}</x-badge>
                            </div>

                            @if ($device->status === 'open')
                                <div class="mt-4 text-sm text-gray-600">
                                    <p>{{ $device->profile_name ?? 'WhatsApp linked' }}</p>
                                    @if ($device->phone_number)<p class="text-gray-400">+{{ $device->phone_number }}</p>@endif
                                </div>
                            @else
                                <div class="mt-4 space-y-3" data-connect-block>
                                    @if ($device->qr_code)
                                        <div class="text-center" data-qr-wrap>
                                            <img src="{{ $device->qr_code }}" alt="QR code" class="mx-auto w-44 h-44 rounded-lg border border-gray-200">
                                            <p class="text-xs text-gray-500 mt-2">Scan with WhatsApp → Linked devices</p>
                                        </div>
                                    @else
                                        <div class="text-center">
                                            <button type="button" class="text-sm text-brand font-medium" onclick="refreshQr({{ $device->id }})">Show QR code</button>
                                        </div>
                                    @endif

                                    {{-- Link with a phone code (no scanning) --}}
                                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                                        <p class="text-xs text-gray-500 mb-2 text-center">Or link with a code — no scanning</p>

                                        <div data-pairing-input class="{{ $device->pairing_code ? 'hidden' : '' }} flex gap-2 justify-center">
                                            <input type="text" inputmode="numeric" placeholder="9715XXXXXXXX" data-pairing-phone
                                                   value="{{ $device->phone_number }}"
                                                   class="rounded-lg border-gray-300 text-sm w-44 focus:ring-brand focus:border-brand">
                                            <button type="button" onclick="getPairingCode({{ $device->id }}, this)"
                                                    class="shrink-0 px-3 rounded-lg bg-brand text-white text-sm font-medium">Get code</button>
                                        </div>

                                        <div data-pairing-code class="{{ $device->pairing_code ? '' : 'hidden' }} text-center">
                                            <p class="text-[11px] text-gray-500">On your phone: <strong>Linked devices → Link with phone number</strong>, then enter:</p>
                                            <p class="text-2xl font-bold tracking-[0.2em] text-gray-800 mt-1 select-all" data-code-value>{{ $device->pairing_code }}</p>
                                            <button type="button" onclick="getPairingCode({{ $device->id }}, this)" class="text-[11px] text-gray-400 hover:text-gray-600 mt-1">Get a new code</button>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-4 flex items-center gap-2">
                                <button type="button" class="text-xs text-gray-500 hover:text-gray-700" onclick="refreshQr({{ $device->id }})">Refresh QR</button>
                                <form method="POST" action="{{ route('devices.destroy', $device) }}" class="ml-auto" onsubmit="return confirm('Remove this device? It will be unlinked from WhatsApp.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-600 hover:text-red-700">Remove</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @push('scripts')
    <script>
        const csrf = document.querySelector('meta[name=csrf-token]').content;

        function refreshQr(id) {
            fetch(`/devices/${id}/connect`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => { if (d.ok) location.reload(); else alert(d.error || 'Could not get a QR code.'); })
                .catch(() => alert('Could not reach the engine.'));
        }

        // Request an 8-digit pairing code for a phone number and show it in place.
        function getPairingCode(id, btn) {
            const block = btn.closest('[data-connect-block]');
            const input = block.querySelector('[data-pairing-phone]');
            const phone = (input.value || '').replace(/\D+/g, '');
            if (phone.length < 8) { alert('Enter the full phone number including country code (digits only).'); input.focus(); return; }

            const original = btn.textContent;
            btn.disabled = true; btn.textContent = '…';

            fetch(`/devices/${id}/connect`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ number: phone }),
            })
                .then(r => r.json())
                .then(d => {
                    if (d.ok && d.pairing) {
                        block.querySelector('[data-pairing-input]').classList.add('hidden');
                        const codeWrap = block.querySelector('[data-pairing-code]');
                        codeWrap.classList.remove('hidden');
                        codeWrap.querySelector('[data-code-value]').textContent = d.pairing;
                    } else {
                        alert(d.error || 'Could not get a code. Check the number and that the device is not already linked.');
                    }
                })
                .catch(() => alert('Could not reach the engine.'))
                .finally(() => { btn.disabled = false; btn.textContent = original; });
        }

        // Poll connection state for devices that are waiting to be scanned.
        document.querySelectorAll('[data-device-id]').forEach(card => {
            const badge = card.querySelector('[data-device-status]');
            if (!badge || badge.textContent.trim() !== 'Waiting for scan') return;
            const id = card.dataset.deviceId;
            const timer = setInterval(() => {
                fetch(`/devices/${id}/state`, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => { if (d.status === 'open') { clearInterval(timer); location.reload(); } })
                    .catch(() => {});
            }, 4000);
        });
    </script>
    @endpush
</x-app-layout>
