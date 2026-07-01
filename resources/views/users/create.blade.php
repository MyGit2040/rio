<x-app-layout>
    <x-slot name="header">Add team member</x-slot>

    <div class="max-w-2xl">
        <x-card title="New team member">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf
                @include('users._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Add member</x-btn>
                    <x-btn :href="route('users.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
