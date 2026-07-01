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
        @forelse ($plans as $plan)
            @php($isCurrent = $current === $plan->key)
            @php($price = rtrim(rtrim(number_format($plan->price, 2), '0'), '.'))
            <x-card class="relative {{ $isCurrent ? 'ring-2 ring-brand' : '' }}">
                @if ($plan->is_popular)
                    <span class="absolute -top-2 right-4 px-2 py-0.5 rounded-full bg-brand text-white text-[11px] font-semibold">Most popular</span>
                @endif
                <div class="flex items-center gap-2">
                    <h3 class="text-lg font-bold text-gray-800">{{ $plan->name }}</h3>
                    @if ($isCurrent)<x-badge color="purple">Current</x-badge>@endif
                </div>
                @if ($plan->description)<p class="mt-1 text-sm text-gray-500">{{ $plan->description }}</p>@endif
                <p class="mt-2">
                    <span class="text-3xl font-bold text-gray-900">${{ $price }}</span>
                    <span class="text-sm text-gray-500">{{ $plan->periodLabel() }}</span>
                    @if ($plan->annual_price)<span class="block text-xs text-gray-400">or ${{ rtrim(rtrim(number_format($plan->annual_price, 2), '0'), '.') }}/yr</span>@endif
                </p>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    @foreach ($plan->featureList as $feature)
                        <li class="flex items-start gap-2 {{ $feature['included'] ? '' : 'text-gray-400 line-through' }}">
                            @if ($feature['included'])
                                <svg class="w-4 h-4 text-green-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="w-4 h-4 text-gray-300 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <span>{{ $feature['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-5">
                    @if ($isCurrent)
                        <x-btn variant="secondary" class="w-full opacity-60 pointer-events-none">Your plan</x-btn>
                    @else
                        <form method="POST" action="{{ route('billing.update') }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="plan" value="{{ $plan->key }}">
                            <x-btn type="submit" variant="primary" class="w-full">Switch to {{ $plan->name }}</x-btn>
                        </form>
                    @endif
                </div>
            </x-card>
        @empty
            <p class="text-gray-500">No plans are available yet.</p>
        @endforelse
    </div>

    <p class="mt-4 text-xs text-gray-400">Plan changes take effect immediately. Payment is arranged with your account manager.</p>
</x-app-layout>
