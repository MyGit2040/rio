<x-app-layout>
    <x-slot name="header">Edit sequence</x-slot>

    <div class="max-w-3xl">
        <x-card title="Edit sequence">
            <form method="POST" action="{{ route('sequences.update', $sequence) }}">
                @csrf @method('PUT')
                @include('sequences._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Save changes</x-btn>
                    <x-btn :href="route('sequences.show', $sequence)" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
