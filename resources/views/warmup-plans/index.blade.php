<x-app-layout>
    <x-slot name="header">Warm-up plans</x-slot>

    <div class="max-w-6xl">
        <x-card title="Gradual daily sending plans" subtitle="Set a separate daily ramp for every WhatsApp number.">
            <p class="text-sm text-gray-600">
                Example: set <strong>Start at 40</strong>, <strong>Increase by 10</strong>, and a final <strong>Daily cap of 200</strong>.
                This number can send 40 today, 50 tomorrow, then continues increasing until it reaches 200.
            </p>
        </x-card>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mt-5">
            @forelse ($devices as $device)
                @php
                    $connected = $device->isConnected();
                    $statusColor = $connected ? 'green' : ($device->status === 'connecting' ? 'yellow' : 'red');
                    $statusLabel = $connected ? 'Connected' : ($device->status === 'connecting' ? 'Waiting for scan' : 'Disconnected');
                @endphp
                <x-card :title="$device->name" :subtitle="($device->phone_number ? '+'.$device->phone_number.' · ' : '').$statusLabel">
                    <x-slot:actions><x-badge :color="$statusColor">{{ $statusLabel }}</x-badge></x-slot:actions>

                    <form method="POST" action="{{ route('warmup-plans.update', $device) }}" class="space-y-4" x-data="{ enabled: {{ $device->warmup_enabled ? 'true' : 'false' }} }">
                        @csrf @method('PATCH')
                        <input type="hidden" name="warmup_enabled" value="0">

                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" name="warmup_enabled" value="1" x-model="enabled" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                            <span class="text-sm font-medium text-gray-800">Enable warm-up for this number
                                <span class="block text-xs font-normal text-gray-500">Campaign messages wait automatically when today's cap is reached.</span>
                            </span>
                        </label>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" :class="{ 'opacity-50': !enabled }">
                            <div>
                                <x-input-label :for="'limit-'.$device->id" value="Final daily cap" />
                                <x-text-input :id="'limit-'.$device->id" name="daily_limit" type="number" min="0" class="block mt-1 w-full" :value="$device->daily_limit" />
                                <p class="text-xs text-gray-500 mt-1">0 = no maximum</p>
                            </div>
                            <div>
                                <x-input-label :for="'start-'.$device->id" value="Start at" />
                                <x-text-input :id="'start-'.$device->id" name="warmup_start" type="number" min="1" class="block mt-1 w-full" :value="$device->warmup_start ?: 20" />
                                <p class="text-xs text-gray-500 mt-1">messages/day</p>
                            </div>
                            <div>
                                <x-input-label :for="'increase-'.$device->id" value="Increase by" />
                                <x-text-input :id="'increase-'.$device->id" name="warmup_per_day" type="number" min="0" class="block mt-1 w-full" :value="$device->warmup_per_day ?: 10" />
                                <p class="text-xs text-gray-500 mt-1">per day</p>
                            </div>
                        </div>

                        @if ($device->warmup_enabled)
                            <div class="rounded-lg bg-blue-50 text-blue-800 px-3 py-2 text-sm">
                                Today: <strong>{{ $device->sentToday() }} / {{ $device->effectiveDailyCap() ?: 'unlimited' }}</strong>
                                @if ($device->warmup_started_at) · started {{ $device->warmup_started_at->format('M j, Y') }} @endif
                            </div>
                        @endif

                        <label class="flex items-center gap-2 text-xs text-gray-600">
                            <input type="checkbox" name="restart" value="1" class="rounded border-gray-300 text-brand focus:ring-brand">
                            Restart this plan from today
                        </label>
                        <div class="flex items-center justify-between gap-3">
                            <a href="{{ route('devices.show', $device) }}" class="text-sm text-brand hover:underline">Device details</a>
                            <x-btn type="submit" variant="primary">Save plan</x-btn>
                        </div>
                    </form>
                </x-card>
            @empty
                <x-card class="xl:col-span-2">
                    <p class="text-gray-500">No WhatsApp numbers yet. <a href="{{ route('devices.index') }}" class="text-brand hover:underline">Connect a device</a> first.</p>
                </x-card>
            @endforelse
        </div>
    </div>
</x-app-layout>
