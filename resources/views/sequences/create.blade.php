<x-app-layout>
    <x-slot name="header">New sequence</x-slot>

    <div class="max-w-3xl">
        <x-card title="Create sequence">
            <form method="POST" action="{{ route('sequences.store') }}">
                @csrf
                @include('sequences._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Create sequence</x-btn>
                    <x-btn :href="route('sequences.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
