@props(['color' => 'gray'])

@php
    $map = [
        'gray'   => 'bg-gray-100 text-gray-700',
        'green'  => 'bg-green-100 text-green-700',
        'red'    => 'bg-red-100 text-red-700',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'blue'   => 'bg-blue-100 text-blue-700',
        'purple' => 'bg-purple-100 text-purple-700',
    ];
    $classes = $map[$color] ?? $map['gray'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium $classes"]) }}>
    {{ $slot }}
</span>
