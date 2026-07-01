@props(['status'])

@php
    $map = [
        'draft'     => ['gray', 'Draft'],
        'scheduled' => ['blue', 'Scheduled'],
        'sending'   => ['yellow', 'Sending'],
        'paused'    => ['purple', 'Paused'],
        'completed' => ['green', 'Completed'],
        'failed'    => ['red', 'Failed'],
    ];
    [$color, $label] = $map[$status] ?? ['gray', ucfirst($status)];
@endphp

<x-badge :color="$color">{{ $label }}</x-badge>
