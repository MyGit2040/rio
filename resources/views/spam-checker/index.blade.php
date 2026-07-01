<x-app-layout>
    <x-slot name="header">Spam checker</x-slot>

    <p class="text-sm text-gray-500 mb-4 max-w-2xl">Paste a message to estimate how spammy it looks. Lower is better — it helps your messages land instead of getting reported or filtered.</p>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Input --}}
        <x-card title="Your message">
            <form method="POST" action="{{ route('spam.check') }}">
                @csrf
                <textarea name="message" rows="10" required
                          class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand"
                          placeholder="Paste your campaign message here…">{{ $message }}</textarea>
                <div class="mt-4 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Check spam score</x-btn>
                    <x-btn :href="route('spam.index')" variant="ghost">Clear</x-btn>
                </div>
            </form>
        </x-card>

        {{-- Result --}}
        <div>
            @if ($result)
                @php
                    $tones = [
                        'low'    => ['green', 'Low risk', 'bg-green-500'],
                        'medium' => ['yellow', 'Medium risk', 'bg-yellow-400'],
                        'high'   => ['red', 'High risk', 'bg-red-500'],
                    ];
                    [$color, $levelLabel, $bar] = $tones[$result['level']];
                @endphp
                <x-card>
                    <div class="flex items-center gap-5">
                        <div class="text-center">
                            <div class="text-5xl font-bold text-gray-800">{{ $result['score'] }}</div>
                            <div class="text-xs text-gray-400">/ 100</div>
                        </div>
                        <div class="flex-1">
                            <x-badge :color="$color">{{ $levelLabel }}</x-badge>
                            <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden mt-2">
                                <div class="h-full {{ $bar }}" style="width: {{ $result['score'] }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">{{ count($result['issues']) }} issue(s) found · {{ $result['stats']['length'] }} characters</p>
                        </div>
                    </div>

                    @if ($result['issues'])
                        <div class="mt-5 pt-4 border-t border-gray-100">
                            <p class="text-sm font-medium text-gray-700 mb-2">What's raising the score</p>
                            <ul class="space-y-2">
                                @foreach ($result['issues'] as $issue)
                                    <li class="flex items-start gap-2 text-sm">
                                        <span class="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full bg-red-50 text-red-600 text-xs font-bold shrink-0">+{{ $issue['points'] }}</span>
                                        <span><span class="font-medium text-gray-800">{{ $issue['label'] }}</span> — <span class="text-gray-500">{{ $issue['detail'] }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($result['suggestions'])
                        <div class="mt-5 pt-4 border-t border-gray-100">
                            <p class="text-sm font-medium text-gray-700 mb-2">How to improve it</p>
                            <ul class="space-y-1.5">
                                @foreach ($result['suggestions'] as $tip)
                                    <li class="flex items-start gap-2 text-sm text-gray-600">
                                        <span class="text-green-500 mt-0.5">✓</span><span>{{ $tip }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-card>
            @else
                <x-card>
                    <div class="text-center text-sm text-gray-500 py-12">
                        <p class="text-4xl mb-3">🛡️</p>
                        Paste a message and press <strong>Check spam score</strong> to see the rating and tips.
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
