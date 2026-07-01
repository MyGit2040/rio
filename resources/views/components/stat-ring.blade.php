@props(['percent' => 0, 'label' => '', 'sublabel' => null, 'color' => '#16a34a'])

@php
    $pct = max(0, min(100, (int) $percent));
    $r = 42;
    $circ = 2 * pi() * $r;
    $offset = $circ * (1 - $pct / 100);
@endphp

<div class="flex items-center gap-4">
    <div class="relative w-20 h-20 shrink-0">
        <svg viewBox="0 0 100 100" class="w-20 h-20 -rotate-90">
            <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="9" />
            <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="{{ $color }}" stroke-width="9"
                    stroke-linecap="round" stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $offset }}" />
        </svg>
        <span class="absolute inset-0 grid place-items-center text-sm font-bold text-gray-800">{{ $pct }}%</span>
    </div>
    <div class="min-w-0">
        <p class="font-medium text-gray-800 truncate">{{ $label }}</p>
        @if ($sublabel)<p class="text-xs text-gray-500 truncate">{{ $sublabel }}</p>@endif
    </div>
</div>
