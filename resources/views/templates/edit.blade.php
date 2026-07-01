<x-app-layout>
    <x-slot name="header">Edit template</x-slot>

    <div class="max-w-2xl">
        <x-card title="Edit template">
            <form method="POST" action="{{ route('templates.update', $template) }}">
                @csrf @method('PUT')
                @include('templates._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Update template</x-btn>
                    <x-btn :href="route('templates.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
