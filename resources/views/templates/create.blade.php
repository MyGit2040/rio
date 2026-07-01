<x-app-layout>
    <x-slot name="header">New template</x-slot>

    @include('templates._studio', ['mode' => 'create', 'action' => route('templates.store'), 'template' => $template])
</x-app-layout>
