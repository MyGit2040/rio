<x-app-layout>
    <x-slot name="header">Billing &amp; plans</x-slot>

    <div class="mb-4">
        <a href="{{ route('settings.edit') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Settings</a>
    </div>

    <x-card title="Current usage" class="mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            @foreach ([
                'devices' => 'WhatsApp numbers',
                'contacts' => 'Contacts',
                'monthly_messages' => 'Messages this month',
            ] as $key => $label)
                @php($u = $usage[$key])
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">{{ $label }}</span>
                        <span class="font-medium text-gray-800">{{ number_format($u['used']) }} / {{ $u['limit'] === 0 ? '∞' : number_format($u['limit']) }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full {{ $u['percent'] >= 90 ? 'bg-red-500' : 'bg-green-500' }}" style="width: {{ $u['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-card>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach ($tiers as $key => $tier)
            <x-card class="{{ $current === $key ? 'ring-2 ring-brand' : '' }}">
                <div class="flex items-center gap-2">
                    <h3 class="text-lg font-bold text-gray-800">{{ $tier['name'] }}</h3>
                    @if ($current === $key)<x-badge color="purple">Current</x-badge>@endif
                </div>
                <p class="mt-2"><span class="text-3xl font-bold text-gray-900">${{ $tier['price'] }}</span><span class="text-sm text-gray-500">/mo</span></p>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    @foreach ($tier['features'] as $feature)
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 text-green-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-5">
                    @if ($current === $key)
                        <x-btn variant="secondary" class="w-full opacity-60 pointer-events-none">Your plan</x-btn>
                    @else
                        <form method="POST" action="{{ route('billing.update') }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="plan" value="{{ $key }}">
                            <x-btn type="submit" variant="primary" class="w-full">Switch to {{ $tier['name'] }}</x-btn>
                        </form>
                    @endif
                </div>
            </x-card>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-400">Plan changes take effect immediately. Payment is arranged with your account manager.</p>
</x-app-layout>
