<x-app-layout>
    <x-slot name="header">New group</x-slot>

    <div class="max-w-xl">
        <x-card title="New group">
            <form method="POST" action="{{ route('groups.store') }}">
                @csrf
                @include('groups._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Create group</x-btn>
                    <x-btn :href="route('groups.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
