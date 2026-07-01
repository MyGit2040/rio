@props(['title' => null, 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl border border-gray-200 shadow-sm']) }}>
    @if ($title || isset($actions))
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <div class="min-w-0">
                @if ($title)<h2 class="font-semibold text-gray-800 truncate">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="text-sm text-gray-500 truncate">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)
                <div class="ml-auto flex items-center gap-2 flex-wrap justify-end">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="{{ isset($flush) ? '' : 'p-5' }}">
        {{ $slot }}
    </div>
</div>
