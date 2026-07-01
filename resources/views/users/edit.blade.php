<x-app-layout>
    <x-slot name="header">Edit team member</x-slot>

    <div class="max-w-2xl">
        <x-card title="Edit team member">
            <form method="POST" action="{{ route('users.update', $user) }}">
                @csrf @method('PUT')
                @include('users._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Save changes</x-btn>
                    <x-btn :href="route('users.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
