@props([
    'action'  => null,       // form target; defaults to current URL
    'search'  => 'Search…',  // search box placeholder (null/'' to hide it)
    'filters' => [],         // ['name' => ['all' => 'All Types', 'options' => [val => label]]]
    'dates'   => [],         // ['created_from' => 'From', 'created_to' => 'To']
    'keep'    => [],         // extra query keys to preserve as hidden inputs (e.g. 'status')
])

@php
    $action = $action ?: url()->current();
    $activeKeys = array_merge($search === null ? [] : ['q'], array_keys($filters), array_keys($dates));
    $hasActive = collect($activeKeys)->contains(fn ($k) => request()->filled($k));
@endphp

<form method="GET" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
    @foreach ((array) $keep as $k)
        @if (request()->filled($k))<input type="hidden" name="{{ $k }}" value="{{ request($k) }}">@endif
    @endforeach

    @if ($search !== null && $search !== '')
        <div class="relative flex-1 min-w-[180px]">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            <input name="q" value="{{ request('q') }}" placeholder="{{ $search }}"
                   class="w-full pl-9 pr-3 rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
        </div>
    @endif

    @foreach ($filters as $name => $cfg)
        <select name="{{ $name }}" onchange="this.form.submit()"
                class="rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand max-w-[180px]">
            <option value="">{{ $cfg['all'] ?? 'All' }}</option>
            @foreach (($cfg['options'] ?? []) as $val => $label)
                <option value="{{ $val }}" @selected((string) request($name) === (string) $val)>{{ $label }}</option>
            @endforeach
        </select>
    @endforeach

    @foreach ($dates as $name => $label)
        <input type="date" name="{{ $name }}" value="{{ request($name) }}" onchange="this.form.submit()"
               title="{{ $label }}" aria-label="{{ $label }}"
               class="rounded-lg border-gray-300 text-sm text-gray-600 focus:ring-brand focus:border-brand">
    @endforeach

    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-brand text-white text-sm font-medium">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 018 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
        Filter
    </button>

    @if ($hasActive)
        <a href="{{ $action }}" class="text-sm text-gray-500 hover:text-gray-700 px-1">Clear</a>
    @endif
</form>
