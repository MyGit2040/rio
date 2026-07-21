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
                                        @php $qrImg = $device->qrImageSrc(); @endphp
                                        <div class="text-center" data-qr-wrap>
                                            @if ($qrImg)
                                                <img src="{{ $qrImg }}" alt="WhatsApp QR code" class="mx-auto w-44 h-44 rounded-lg border border-gray-200" />
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

        // Draw any raw-payload QR canvases. renderOpenWaQr lives in the Vite
        // bundle, which loads as a deferred type="module" and so has NOT run yet
        // at this point in this classic inline script. Calling it now threw
        // "renderOpenWaQr is not a function", which aborted the rest of this
        // script — including the poll below — and left the box empty. Defer to
        // DOMContentLoaded, which fires after deferred modules execute. (Image
        // QRs render as a plain <img> and need none of this.)
        function drawQrCanvases() {
            document.querySelectorAll('[data-openwa-qr]').forEach(c => window.renderOpenWaQr && window.renderOpenWaQr(c, c.dataset.qrPayload));
        }
        if (window.renderOpenWaQr) drawQrCanvases();
        else document.addEventListener('DOMContentLoaded', drawQrCanvases);

        function refreshQr(id, attempt = 0) {
            fetch(`/devices/${id}/connect`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) {
                        alert(d.error || 'Could not get a QR code.');
                        return;
                    }
                    // OpenWA creates a QR asynchronously after a session starts.
                    // Keep asking briefly instead of reloading a blank card.
                    if (d.qr) {
                        location.reload();
                    } else if (d.status !== 'open' && attempt < 10) {
                        setTimeout(() => refreshQr(id, attempt + 1), 3000);
                    } else if (d.status !== 'open') {
                        alert('QR generation is taking longer than expected. Please try again in a minute.');
                    }
                })
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
            // The QR currently on screen, so a poll only acts when WhatsApp has
            // actually rotated the code (~every 20s).
            let shown = card.querySelector('img[alt="WhatsApp QR code"]')?.getAttribute('src')
                     || card.querySelector('[data-openwa-qr]')?.dataset.qrPayload
                     || null;
            const timer = setInterval(() => {
                fetch(`/devices/${id}/state`, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        // Connected — swap the card to its linked state.
                        if (d.status === 'open') { clearInterval(timer); location.reload(); return; }
                        // Scan landed; the engine is finalising the link. Tell the
                        // operator it worked instead of showing the QR + "Waiting
                        // for scan", and keep polling until it reports 'open'.
                        if (d.linking) {
                            badge.textContent = 'Connecting…';
                            const block = card.querySelector('[data-connect-block]');
                            if (block) block.style.display = 'none';
                            return;
                        }
                        if (!d.qr || d.qr === shown) return;
                        shown = d.qr;
                        const isImage = d.qr.startsWith('data:image/') || d.qr.startsWith('iVBOR');
                        if (isImage) {
                            const src = d.qr.startsWith('data:') ? d.qr : 'data:image/png;base64,' + d.qr;
                            const img = card.querySelector('img[alt="WhatsApp QR code"]');
                            // Refresh the image in place; if the card has no <img>
                            // yet (was empty, a button, or a canvas), one reload
                            // renders it through the view's <img> path.
                            if (img) img.src = src;
                            else { clearInterval(timer); location.reload(); }
                        } else {
                            // Raw-payload fallback: redraw the canvas in place, or
                            // reload once to obtain a canvas to draw onto.
                            const canvas = card.querySelector('[data-openwa-qr]');
                            if (canvas && window.renderOpenWaQr) window.renderOpenWaQr(canvas, d.qr);
                            else { clearInterval(timer); location.reload(); }
                        }
                    })
                    .catch(() => {});
            // A completed QR/pairing login is time-sensitive in the UI. Poll at
            // one second so the card switches to Connected almost immediately,
            // while the request itself remains a lightweight session-status read.
            }, 1000);
        });
    </script>
    @endpush
</x-app-layout>
