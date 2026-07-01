<x-app-layout>
    <x-slot name="header">Number health</x-slot>

    <p class="mb-4 text-sm text-gray-500">Live status and 7-day activity for each connected number. A monitoring aid — reconnect or slow down a number showing a high failure rate.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        @forelse ($devices as $row)
            @php($d = $row['device'])
            <x-card>
                <div class="flex items-center gap-3 mb-4">
                    <span class="grid place-items-center w-10 h-10 rounded-lg bg-gray-100 text-gray-600 font-semibold">
                        {{ strtoupper(substr($d->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-800 truncate">{{ $d->name }}</p>
                        <p class="text-xs text-gray-500 truncate">{{ $d->phone_number ? '+'.$d->phone_number : $d->instance_name }}</p>
                    </div>
                    <span class="ml-auto">
                        <x-badge :color="$d->status === 'open' ? 'green' : ($d->status === 'connecting' ? 'yellow' : 'red')">
                            {{ $d->status === 'open' ? 'Connected' : ucfirst($d->status) }}
                        </x-badge>
                    </span>
                </div>

                <div class="mb-3">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Today: {{ $row['sent_today'] }}{{ $row['cap'] > 0 ? ' / '.$row['cap'] : '' }}</span>
                        <span>{{ $row['cap'] > 0 ? $row['cap_percent'].'%' : 'no cap' }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full {{ $row['cap_percent'] >= 90 ? 'bg-red-500' : 'bg-green-500' }}" style="width: {{ $row['cap'] > 0 ? $row['cap_percent'] : 0 }}%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center mt-4">
                    <div><p class="text-lg font-bold text-gray-800">{{ $row['sent_7d'] }}</p><p class="text-[11px] text-gray-500">Sent 7d</p></div>
                    <div><p class="text-lg font-bold text-red-500">{{ $row['failed_7d'] }}</p><p class="text-[11px] text-gray-500">Failed 7d</p></div>
                    <div><p class="text-lg font-bold {{ $row['failure_rate'] >= 20 ? 'text-red-500' : 'text-gray-800' }}">{{ $row['failure_rate'] }}%</p><p class="text-[11px] text-gray-500">Fail rate</p></div>
                </div>

                @if ($d->warmup_enabled)
                    <p class="mt-3 text-xs text-blue-600 bg-blue-50 rounded-lg px-3 py-2">🌱 Warm-up on — today's ceiling {{ $row['cap'] }}.</p>
                @endif

                <a href="{{ route('devices.show', $d) }}" class="mt-4 block text-center text-sm text-green-600 hover:text-green-700">Manage device</a>
            </x-card>
        @empty
            <div class="md:col-span-2 xl:col-span-3">
                <x-card><p class="text-center text-gray-500 py-8">No devices yet. <a href="{{ route('devices.index') }}" class="text-green-600">Add a WhatsApp number</a> to see its health.</p></x-card>
            </div>
        @endforelse
    </div>
</x-app-layout>
