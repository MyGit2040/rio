<x-app-layout>
    <x-slot name="header">Devices</x-slot>

    @unless ($engineReady)
        <div class="mb-6 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 px-5 py-4 text-sm flex items-center gap-3 flex-wrap">
            <span>The OpenWA engine isn't connected yet — you need it before linking a WhatsApp number.</span>
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
                    <x-btn type="submit" variant="primary" class="w-full" :disabled="! $engineReady">Create &amp; link</x-btn>
                    <p class="text-xs text-gray-500">After creating, scan the QR code or use WhatsApp's Link with phone number option.</p>
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
                                            @if (\Illuminate\Support\Str::startsWith($device->qr_code, 'data:image/'))
                                                <img src="{{ $device->qr_code }}" alt="WhatsApp QR code" class="mx-auto w-44 h-44 rounded-lg border border-gray-200" />
                                            @else
                                                <canvas data-openwa-qr data-qr-payload="{{ $device->qr_code }}" aria-label="WhatsApp QR code" class="mx-auto w-44 h-44 rounded-lg border border-gray-200"></canvas>
                                            @endif
                                            <p class="text-xs text-gray-500 mt-2">Scan with WhatsApp → Linked devices</p>
                                        </div>
                                    @else
                                        <div class="text-center">
                                            <button type="button" class="text-sm text-brand font-medium" onclick="refreshQr({{ $device->id }})">Show QR code</button>
                                        </div>
                                    @endif

                                    <div class="border-t border-gray-100 pt-3" data-pairing-wrap>
                                        <p class="text-xs font-medium text-gray-700">Or link with a phone number</p>
                                        <p class="mt-1 text-xs text-gray-500">In WhatsApp: Linked devices → Link with phone number.</p>
                                        @if ($device->pairing_code)
                                            <div class="mt-2 rounded-lg bg-brand/10 px-3 py-2 text-center">
                                                <span class="text-xs text-gray-600">Pairing code</span>
                                                <p class="font-mono text-lg font-bold tracking-[0.2em] text-gray-900">{{ $device->pairing_code }}</p>
                                            </div>
                                        @endif
                                        <div class="mt-2 flex gap-2">
                                            <input type="tel" inputmode="numeric" data-pairing-phone placeholder="971501234567" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm focus:border-brand focus:ring-brand">
                                            <button type="button" class="shrink-0 rounded-lg bg-brand px-3 py-2 text-xs font-medium text-white hover:opacity-90" onclick="getPairingCode({{ $device->id }}, this)">Get code</button>
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

        document.querySelectorAll('[data-openwa-qr]').forEach(canvas => window.renderOpenWaQr(canvas, canvas.dataset.qrPayload));

        function refreshQr(id) {
            fetch(`/devices/${id}/connect`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => { if (d.ok) location.reload(); else alert(d.error || 'Could not get a QR code.'); })
                .catch(() => alert('Could not reach the engine.'));
        }

        function getPairingCode(id, button) {
            const card = button.closest('[data-device-id]');
            const phone = card.querySelector('[data-pairing-phone]').value;
            if (!phone.trim()) {
                alert('Enter the WhatsApp phone number with country code first.');
                return;
            }
            button.disabled = true;
            button.textContent = 'Getting…';
            fetch(`/devices/${id}/pairing-code`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone_number: phone })
            })
                .then(r => r.json())
                .then(d => { if (d.ok) location.reload(); else alert(d.error || 'Could not get a pairing code.'); })
                .catch(() => alert('Could not reach the engine.'))
                .finally(() => { button.disabled = false; button.textContent = 'Get code'; });
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
