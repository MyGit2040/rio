<x-app-layout>
    <x-slot name="header">{{ $device->name }}</x-slot>

    <a href="{{ route('devices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; All devices</a>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-4">
        <div>
            <x-card title="Device">
                @php
                    $statusColor = ['open' => 'green', 'connecting' => 'yellow', 'close' => 'red'][$device->status] ?? 'gray';
                    $statusLabel = ['open' => 'Connected', 'connecting' => 'Waiting for scan', 'close' => 'Disconnected'][$device->status] ?? ucfirst($device->status);
                @endphp
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Status</dt><dd><x-badge :color="$statusColor">{{ $statusLabel }}</x-badge></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Instance</dt><dd class="text-gray-800 truncate">{{ $device->instance_name }}</dd></div>
                    @if ($device->phone_number)<div class="flex justify-between gap-3"><dt class="text-gray-500">Number</dt><dd class="text-gray-800">+{{ $device->phone_number }}</dd></div>@endif
                    @if ($device->connected_at)<div class="flex justify-between gap-3"><dt class="text-gray-500">Linked</dt><dd class="text-gray-800">{{ $device->connected_at->format('M j, Y') }}</dd></div>@endif
                </dl>
            </x-card>

            <x-card title="Sending limit" class="mt-6">
                <p class="text-sm text-gray-600 mb-3">Sent today:
                    <span class="font-semibold text-gray-800">{{ $device->sentToday() }}</span>@if ($device->daily_limit) / {{ $device->daily_limit }}@endif
                </p>
                <form method="POST" action="{{ route('devices.update', $device) }}" class="space-y-3">
                    @csrf @method('PATCH')
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $device->name)" required />
                    </div>
                    <div>
                        <x-input-label for="daily_limit" value="Daily send cap (0 = unlimited)" />
                        <x-text-input id="daily_limit" name="daily_limit" type="number" min="0" class="block mt-1 w-full" :value="old('daily_limit', $device->daily_limit)" />
                        <p class="text-xs text-gray-500 mt-1">Once reached, extra messages wait until tomorrow.</p>
                    </div>
                    <x-btn type="submit" variant="primary">Save</x-btn>
                </form>
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card title="Privacy" subtitle="Applies to this WhatsApp number.">
                @unless ($device->isConnected())
                    <div class="rounded-lg bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 text-sm mb-4">Connect this device to change its privacy settings.</div>
                @endunless

                <form method="POST" action="{{ route('devices.privacy', $device) }}" class="space-y-4">
                    @csrf
                    @php
                        $sel = fn ($key, $default) => data_get($privacy, $key, data_get($privacy, "privacySettings.$key", $default));
                        $who = ['all' => 'Everyone', 'contacts' => 'My contacts', 'none' => 'Nobody'];
                    @endphp

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="last" value="Last seen" />
                            <select id="last" name="last" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                                @foreach ($who as $v => $l)<option value="{{ $v }}" @selected($sel('last', 'all') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="profile" value="Profile photo" />
                            <select id="profile" name="profile" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                                @foreach ($who as $v => $l)<option value="{{ $v }}" @selected($sel('profile', 'all') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="status" value="Status / About" />
                            <select id="status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                                @foreach ($who as $v => $l)<option value="{{ $v }}" @selected($sel('status', 'all') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="online" value="Online status" />
                            <select id="online" name="online" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                                <option value="all" @selected($sel('online', 'all') === 'all')>Everyone</option>
                                <option value="match_last_seen" @selected($sel('online', 'all') === 'match_last_seen')>Same as last seen</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="readreceipts" value="Read receipts" />
                            <select id="readreceipts" name="readreceipts" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                                <option value="all" @selected($sel('readreceipts', 'all') === 'all')>On</option>
                                <option value="none" @selected($sel('readreceipts', 'all') === 'none')>Off</option>
                            </select>
                        </div>
                    </div>

                    <x-btn type="submit" variant="primary" :disabled="! $device->isConnected()">Save privacy</x-btn>
                </form>

                <p class="text-xs text-gray-500 mt-4">Disappearing-message timers are only available for group chats on this engine, not one-to-one chats.</p>
            </x-card>
        </div>
    </div>
</x-app-layout>
