<x-app-layout>
    <x-slot name="header">Edit template</x-slot>

    @include('templates._studio', ['mode' => 'edit', 'action' => route('templates.update', $template), 'template' => $template])
</x-app-layout>
